<?php

namespace App\Http\Controllers;

use App\Actions\ConfirmOnlineQrDeliveryAction;
use App\Actions\ConvertOnlineOrderRequestAction;
use App\Actions\ResolveOnlineQrDispositionAction;
use App\Enums\CashSessionStatus;
use App\Enums\DynamicQrStatus;
use App\Enums\MembershipRole;
use App\Models\CashSession;
use App\Models\DynamicQrIntent;
use App\Models\OnlineOrderRequest;
use App\Models\Order;
use App\Models\User;
use App\Support\CompanyContext;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class OnlineOrderRequestController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', OnlineOrderRequest::class);
        $filters = $request->validate(['status' => ['nullable', Rule::in(['pending_confirmation', 'confirmed', 'cancelled', 'paid', 'payment_preparation', 'preparation_invalidated'])], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])]]);
        $query = OnlineOrderRequest::query()->forCompany($this->company())->where('branch_id', $this->branch()->id)->where('is_fence', false)->whereIn('status', ['pending_confirmation', 'confirmed', 'cancelled', 'paid', 'payment_preparation', 'preparation_invalidated'])
            ->withSum('items', 'quantity')->withCount(['items as unclassified_items_count' => fn ($query) => $query->whereNull('was_promotion')]);
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', CarbonImmutable::createFromFormat('!Y-m-d', $filters['from'], 'America/La_Paz')->utc());
        }
        if (! empty($filters['to'])) {
            $query->where('created_at', '<', CarbonImmutable::createFromFormat('!Y-m-d', $filters['to'], 'America/La_Paz')->addDay()->utc());
        }

        return view('online-orders.index', ['orders' => $query->latest()->paginate(30)->withQueryString(), 'filters' => $filters]);
    }

    public function show(string $id): View
    {
        $order = $this->order($id)->load(['items', 'resolvedBy', 'posOrder', 'legacyReviewedBy']);
        Gate::authorize('view', $order);
        $phone = preg_replace('/\D+/', '', $order->customer_phone);
        if (strlen($phone) === 8) {
            $phone = '591'.$phone;
        }
        $message = 'Hola, te contactamos por tu solicitud '.$order->publicCode().($order->fulfillment === 'delivery' ? ' para coordinar el delivery.' : ' para coordinar la hora de recojo.');

        $operation = Schema::hasTable('online_qr_operations') && Schema::hasColumn('online_qr_operations', 'dynamic_qr_intent_id')
            ? DB::table('online_qr_operations')->where('online_order_request_id', $order->id)
                ->where('company_id', $order->company_id)->where('branch_id', $order->branch_id)->first() : null;
        $bankIntent = $operation?->dynamic_qr_intent_id ? DynamicQrIntent::query()->where('company_id', $order->company_id)
            ->where('branch_id', $order->branch_id)->where('order_id', $order->pos_order_id)->find($operation->dynamic_qr_intent_id) : null;

        $canResolve = in_array(app(CompanyContext::class)->membership()->role, [MembershipRole::Owner, MembershipRole::Admin], true);
        $resolution = $canResolve && $bankIntent && Schema::hasTable('online_qr_resolutions') ? DB::table('online_qr_resolutions')->where('dynamic_qr_intent_id', $bankIntent->id)->first() : null;
        if ($resolution) {
            $resolution->reason = Crypt::decryptString($resolution->reason);
            $resolution->evidence = Crypt::decryptString($resolution->evidence);
            $resolution->resolved_at = CarbonImmutable::parse($resolution->resolved_at, 'UTC');
            $resolution->actor = User::find($resolution->resolved_by);
        }

        $dispositionsAvailable = Schema::hasTable('online_qr_dispositions');
        $disposition = $canResolve && $operation && $dispositionsAvailable
            ? DB::table('online_qr_dispositions')->where('online_qr_operation_id', $operation->id)->first() : null;
        if ($disposition) {
            $disposition->reason = Crypt::decryptString($disposition->reason);
            $disposition->evidence = Crypt::decryptString($disposition->evidence);
            $disposition->inventory_result = json_decode(Crypt::decryptString($disposition->inventory_result), true);
            $disposition->actor = User::find($disposition->resolved_by);
            $disposition->resolved_at = CarbonImmutable::parse($disposition->resolved_at, 'UTC');
        }
        $inventoryOutcome = $canResolve && $disposition && Schema::hasTable('online_qr_inventory_outcomes')
            ? DB::table('online_qr_inventory_outcomes')->where('online_qr_disposition_id', $disposition->id)->first() : null;
        if ($inventoryOutcome) {
            $inventoryOutcome->reason = Crypt::decryptString($inventoryOutcome->reason);
            $inventoryOutcome->evidence = Crypt::decryptString($inventoryOutcome->evidence);
            $inventoryOutcome->actor = User::find($inventoryOutcome->resolved_by);
        }
        $reservations = $canResolve && $order->posOrder ? $order->posOrder->reservations()->with('inventoryItem')->get() : collect();

        return view('online-orders.show', ['canResolve' => $canResolve, 'resolution' => $resolution, 'disposition' => $disposition, 'inventoryOutcome' => $inventoryOutcome, 'inventoryOutcomeAvailable' => Schema::hasTable('online_qr_inventory_outcomes'), 'dispositionsAvailable' => $dispositionsAvailable, 'reservations' => $reservations, 'order' => $order, 'bankIntent' => $bankIntent, 'whatsapp' => 'https://wa.me/'.$phone.'?text='.rawurlencode($message)]);
    }

    public function confirmQrDelivery(Request $request, string $id, ConfirmOnlineQrDeliveryAction $action): RedirectResponse
    {
        abort_unless(in_array(app(CompanyContext::class)->membership()->role, [MembershipRole::Owner, MembershipRole::Admin], true), 403);
        abort_unless(Schema::hasTable('online_qr_inventory_outcomes'), 503);
        $online = $this->order($id);
        Gate::authorize('view', $online);
        $action->execute($online, $request->user(), $request->only(['action_date', 'reason', 'evidence', 'confirmed']));

        return redirect()->route('online-orders.show', $id)->with('online_order_notice', 'Entrega documentada e inventario dispuesto. No se creó un pago POS.');
    }

    public function resolveQrDisposition(Request $request, string $id, ResolveOnlineQrDispositionAction $action): RedirectResponse
    {
        abort_unless(in_array(app(CompanyContext::class)->membership()->role, [MembershipRole::Owner, MembershipRole::Admin], true), 403);
        abort_unless(Schema::hasTable('online_qr_dispositions'), 503);
        $data = $request->validate([
            'disposition' => ['required', Rule::in(['accounting', 'refund', 'cancel'])],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'evidence' => ['required', 'string', 'min:10', 'max:2000'],
            'action_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'amount' => ['required', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'confirmed' => ['accepted'],
        ]);
        $online = $this->order($id);
        Gate::authorize('view', $online);
        $action->execute($online, $request->user(), $data);

        return redirect()->route('online-orders.show', $id)->with('online_order_notice', 'Registro administrativo guardado. El inventario sigue pendiente de disposición; no se ejecutó ningún pago ni devolución bancaria.');
    }

    public function transition(Request $request, string $id, ConvertOnlineOrderRequestAction $convert): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['confirmed', 'cancelled'])]]);
        try {
            $updated = DB::transaction(function () use ($id, $data, $request, $convert): bool {
                $order = $this->order($id, true);
                Gate::authorize($data['status'] === 'cancelled' ? 'cancel' : 'update', $order);
                if ($order->pos_order_id || $order->status !== 'pending_confirmation') {
                    return false;
                }
                if ($data['status'] === 'cancelled' && $order->items()->whereNull('was_promotion')->exists()) {
                    throw new DomainException('Esta solicitud necesita una revisión histórica registrada antes de cancelarse.');
                }
                $attributes = ['status' => $data['status'], 'resolved_by' => $request->user()->id, 'resolved_at' => now()];
                if ($data['status'] === 'confirmed') {
                    $posOrder = $convert->execute($order, $request->user());
                    $attributes += ['pos_order_id' => $posOrder->id, 'converted_by' => $request->user()->id, 'converted_at' => now()];
                }
                $order->update($attributes);

                return true;
            }, 3);
        } catch (DomainException $exception) {
            return redirect()->route('online-orders.show', $id)->with('online_order_notice', $exception->getMessage());
        }

        $redirect = redirect()->route('online-orders.show', $id);

        return $updated ? $redirect : $redirect->with('online_order_notice', 'Esta solicitud ya fue atendida. Se conserva el estado actual.');
    }

    public function resolveLegacy(Request $request, string $id): RedirectResponse
    {
        $data = $request->validate([
            'note' => ['required', 'string', 'min:3', 'max:500'],
            'contacted' => ['accepted'],
        ]);
        $updated = DB::transaction(function () use ($id, $request, $data): bool {
            $order = $this->order($id, true);
            Gate::authorize('cancel', $order);
            if ($order->status !== 'pending_confirmation' || $order->pos_order_id) {
                return false;
            }
            if (! $order->items()->whereNull('was_promotion')->exists()) {
                throw ValidationException::withMessages(['note' => 'La solicitud no necesita esta revisión histórica.']);
            }
            $now = now();
            $order->update([
                'status' => 'cancelled', 'resolved_by' => $request->user()->id, 'resolved_at' => $now,
                'legacy_review_resolution' => 'cancelled_after_contact', 'legacy_review_note' => trim($data['note']),
                'legacy_reviewed_by' => $request->user()->id, 'legacy_reviewed_at' => $now,
            ]);

            return true;
        }, 3);

        return redirect()->route('online-orders.show', $id)->with('online_order_notice', $updated
            ? 'Revisión registrada y solicitud cancelada. Si corresponde, pide al cliente que envíe una nueva solicitud.'
            : 'Esta solicitud ya fue atendida. Se conserva el estado actual.');
    }

    public function resolveClosedPayment(Request $request, string $id): RedirectResponse
    {
        abort_unless(in_array(app(CompanyContext::class)->membership()->role, [MembershipRole::Owner, MembershipRole::Admin], true), 403);
        $data = $request->validate([
            'resolution' => ['required', Rule::in(['accounting', 'refund'])],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'evidence' => ['required', 'string', 'min:10', 'max:2000'],
            'confirmed' => ['accepted'],
        ]);
        $online = $this->order($id);
        Gate::authorize('view', $online);
        $operation = DB::table('online_qr_operations')->where('online_order_request_id', $online->id)
            ->where('company_id', $online->company_id)->where('branch_id', $online->branch_id)->first();
        if (! $operation || ! $operation->dynamic_qr_intent_id || ! $operation->pos_order_id) {
            throw ValidationException::withMessages(['resolution' => 'No existe una operación bancaria vinculada.']);
        }
        DB::transaction(function () use ($operation, $online, $request, $data): void {
            $cash = CashSession::query()->lockForUpdate()->findOrFail($operation->cash_session_id);
            $posOrder = Order::query()->lockForUpdate()->findOrFail($operation->pos_order_id);
            $intent = DynamicQrIntent::query()->lockForUpdate()->findOrFail($operation->dynamic_qr_intent_id);
            $operation = DB::table('online_qr_operations')->where('id', $operation->id)->lockForUpdate()->first();
            $online = $this->order((string) $online->id, true);
            $evidence = $intent->reconciliation_data ?? [];
            if ($cash->status !== CashSessionStatus::Closed || $intent->status !== DynamicQrStatus::ReconciliationRequired
                || $intent->bank_status !== '1' || $intent->payment_id !== null
                || (int) $intent->company_id !== (int) $online->company_id || (int) $intent->branch_id !== (int) $online->branch_id
                || (int) $intent->cash_session_id !== (int) $cash->id || (int) $intent->order_id !== (int) $posOrder->id
                || (int) $operation->online_order_request_id !== (int) $online->id || (int) $online->pos_order_id !== (int) $posOrder->id
                || ($evidence['qrId'] ?? null) !== $intent->bank_qr_id || ($evidence['currency'] ?? null) !== $intent->currency
                || ! is_string($evidence['amount'] ?? null) || ! preg_match('/^\d+(?:\.\d{1,2})?$/D', $evidence['amount'])
                || ! BigDecimal::of($evidence['amount'])->isEqualTo($intent->amount)
                || ! BigDecimal::of($online->total)->isEqualTo($intent->amount)) {
                throw ValidationException::withMessages(['resolution' => 'La incidencia no corresponde a un pago confirmado de esta caja cerrada.']);
            }
            if (DB::table('online_qr_resolutions')->where('dynamic_qr_intent_id', $intent->id)->exists()) {
                throw ValidationException::withMessages(['resolution' => 'Esta incidencia ya tiene una resolución registrada.']);
            }
            if (Schema::hasTable('online_qr_dispositions') && DB::table('online_qr_dispositions')->where('dynamic_qr_intent_id', $intent->id)->exists()) {
                throw ValidationException::withMessages(['resolution' => 'Esta incidencia ya tiene una disposición final.']);
            }
            DB::table('online_qr_resolutions')->insert([
                'dynamic_qr_intent_id' => $intent->id, 'resolved_by' => $request->user()->id,
                'resolution' => $data['resolution'], 'reason' => Crypt::encryptString(trim($data['reason'])),
                'evidence' => Crypt::encryptString(trim($data['evidence'])), 'resolved_at' => now(),
            ]);
        }, 3);

        return redirect()->route('online-orders.show', $id)->with('online_order_notice', 'Resolución administrativa registrada. No se creó un pago ni se ejecutó una devolución bancaria.');
    }

    private function order(string $id, bool $lock = false): OnlineOrderRequest
    {
        $query = OnlineOrderRequest::query()->forCompany($this->company())->where('branch_id', $this->branch()->id)->where('is_fence', false);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->findOrFail($id);
    }
}
