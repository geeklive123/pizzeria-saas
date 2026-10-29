<?php

namespace App\Http\Controllers;

use App\Enums\DynamicQrStatus;
use App\Enums\MembershipRole;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\BankQrDiagnostic;
use App\Models\BankQrSetting;
use App\Models\Branch;
use App\Models\DynamicQrIntent;
use App\Models\Payment;
use App\Services\BancoEconomicoGateway;
use App\Services\BankQrHistory;
use App\Support\CompanyContext;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Throwable;

class BankQrHistoryController extends Controller
{
    public function index(Request $request, BankQrHistory $history)
    {
        $this->authorizeAdmin();
        $today = now()->timezone('America/La_Paz')->toDateString();
        $data = Validator::make($request->query(), [
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'],
            'branch' => ['nullable', 'integer'], 'order' => ['nullable', 'digits_between:1,12'],
            'reference' => ['nullable', 'string', 'max:32'], 'qr' => ['nullable', 'string', 'max:190'],
            'state' => ['nullable', 'in:matched,bank_only,local_only,mismatch,review,unverified,diagnostic_paid'],
            'page' => ['nullable', 'integer', 'min:1'],
        ])->validate();
        $filters = ['from' => $data['from'] ?? now()->timezone('America/La_Paz')->subDays(30)->toDateString(), 'to' => $data['to'] ?? $today,
            'branch' => (string) ($data['branch'] ?? ''), 'order' => $data['order'] ?? '', 'reference' => $data['reference'] ?? '', 'qr' => $data['qr'] ?? '', 'state' => $data['state'] ?? ''];
        $from = CarbonImmutable::parse($filters['from']);
        $to = CarbonImmutable::parse($filters['to']);
        abort_if($to->lessThan($from) || $from->diffInDays($to) > 31, 422, 'El rango debe ser de hasta 32 días.');
        $branches = Branch::query()->forCompany($this->company())->orderBy('name')->get();
        abort_if($filters['branch'] !== '' && ! $branches->contains('id', (int) $filters['branch']), 404);
        $settings = BankQrSetting::query()->forCompany($this->company())->orderByDesc('id')->get(['id', 'company_id', 'branch_id', 'active_scope']);
        $snapshot = $request->session()->get('bank_history_snapshot');
        if (! is_array($snapshot) || ($snapshot['company_id'] ?? null) !== $this->company()->id || ($snapshot['user_id'] ?? null) !== $request->user()->id
            || ($snapshot['date'] ?? '') < $filters['from'] || ($snapshot['date'] ?? '') > $filters['to'] || ($snapshot['expires'] ?? 0) < time()) {
            $snapshot = null;
        }
        $report = $history->report($this->company(), $filters, $snapshot, (int) ($data['page'] ?? 1));
        $diagnosticIds = $report['paginator']->getCollection()->pluck('diagnostic')->filter()->pluck('id');
        $events = DB::table('bank_qr_diagnostic_events')->where('company_id', $this->company()->id)
            ->whereIn('diagnostic_id', $diagnosticIds)->orderByDesc('id')->get()->groupBy('diagnostic_id');
        $evidenceAvailable = Schema::hasTable('bank_qr_deposit_evidence');
        $intentIds = $report['paginator']->getCollection()->pluck('intent')->filter()->pluck('id');
        $depositEvidence = $evidenceAvailable ? DB::table('bank_qr_deposit_evidence')->where('company_id', $this->company()->id)
            ->whereIn('intent_id', $intentIds)->orderByDesc('id')->get()->map(function ($row) {
                $row->details = Crypt::decryptString($row->details);

                return $row;
            })->groupBy('intent_id') : collect();

        return response()->view('bank-settings.history', $report + compact('filters', 'branches', 'settings', 'snapshot', 'events', 'evidenceAvailable', 'depositEvidence'))
            ->header('Cache-Control', 'private, no-store');
    }

    public function consult(Request $request, BancoEconomicoGateway $gateway)
    {
        $this->authorizeAdmin();
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'setting_id' => ['required', 'integer']]);
        $setting = BankQrSetting::query()->forCompany($this->company())->findOrFail($data['setting_id']);
        // Discard old evidence on every attempt, including failures.
        $request->session()->forget('bank_history_snapshot');
        try {
            $payments = $gateway->paidOn($setting, str_replace('-', '', $data['date']));
            if (count($payments) > 1000) {
                throw new \RuntimeException('Bank list exceeds the supported limit.');
            }
            $request->session()->put('bank_history_snapshot', ['company_id' => $this->company()->id, 'user_id' => $request->user()->id,
                'setting_id' => $setting->id, 'date' => $data['date'], 'payments' => $payments, 'expires' => time() + 900]);
        } catch (Throwable) {
            return redirect()->route('bank-qr-history.index', ['from' => $data['date'], 'to' => $data['date']])->withErrors(['bank' => 'No se pudo consultar una lista bancaria válida. No se puede inferir ausencia de pagos.']);
        }

        return redirect()->route('bank-qr-history.index', ['from' => $data['date'], 'to' => $data['date']])->with('status', 'Consulta bancaria de solo lectura completada.');
    }

    public function status(Request $request, string $ulid, BancoEconomicoGateway $gateway)
    {
        $this->authorizeAdmin();
        $data = $request->validate(['kind' => ['required', 'in:sale,diagnostic']]);
        $record = $data['kind'] === 'sale'
            ? DynamicQrIntent::query()->forCompany($this->company())->where('ulid', $ulid)->firstOrFail()
            : BankQrDiagnostic::query()->forCompany($this->company())->where('ulid', $ulid)->firstOrFail();
        if (! $record->bank_qr_id) {
            return back()->withErrors(['bank' => 'La operación no tiene identificador QR para consultar.']);
        }
        try {
            $response = $gateway->status($record);
            $status = $response['statusQrCode'] ?? $response['statusQRCode'] ?? null;
            $status = is_string($status) && in_array($status, ['0', '1', '9'], true) ? $status : 'desconocido';
            Log::info('baneco_status_check', ['intent' => $record->ulid, 'company_id' => $record->company_id, 'result' => $status, 'source' => 'history']);

            return back()->with('status', 'Consulta individual de solo lectura · QR '.$record->bank_qr_id.' · estado bancario '.$status.'. No se modificaron pagos.');
        } catch (Throwable) {
            Log::warning('baneco_status_check', ['intent' => $record->ulid, 'company_id' => $record->company_id, 'result' => 'error', 'source' => 'history']);

            return back()->withErrors(['bank' => 'No se pudo consultar el estado bancario.']);
        }
    }

    public function recordDeposit(Request $request, string $ulid)
    {
        $this->authorizeAdmin();
        abort_unless(Schema::hasTable('bank_qr_deposit_evidence'), 503, 'La evidencia de abono requiere una migración pendiente.');
        $data = $request->validate([
            'request_key' => ['required', 'ulid'], 'deposit_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'details' => ['required', 'string', 'min:20', 'max:1000'], 'confirmed' => ['required', 'accepted'],
        ]);
        $company = $this->company();
        DB::transaction(function () use ($company, $request, $data, $ulid): void {
            $intent = DynamicQrIntent::query()->forCompany($company)->where('ulid', $ulid)->lockForUpdate()->firstOrFail();
            $payment = Payment::query()->whereKey($intent->payment_id)->lockForUpdate()->first();
            abort_unless($intent->status === DynamicQrStatus::Paid && $intent->bank_status === '1' && $intent->currency === 'BOB'
                && $payment && $payment->status === PaymentStatus::Completed && $payment->method === PaymentMethod::Qr
                && $payment->company_id === $intent->company_id && $payment->branch_id === $intent->branch_id
                && $payment->order_id === $intent->order_id && $payment->kitchen_dispatch_id === $intent->kitchen_dispatch_id
                && BigDecimal::of($payment->amount)->isEqualTo($intent->amount), 422);
            $existing = DB::table('bank_qr_deposit_evidence')->where('request_key', $data['request_key'])->first();
            if ($existing) {
                abort_unless($existing->company_id === $company->id && $existing->intent_id === $intent->id
                    && $existing->user_id === $request->user()->id && $existing->deposit_date === $data['deposit_date']
                    && Crypt::decryptString($existing->details) === $data['details'], 422);

                return;
            }
            DB::table('bank_qr_deposit_evidence')->insert([
                'request_key' => $data['request_key'], 'company_id' => $company->id, 'branch_id' => $intent->branch_id,
                'intent_id' => $intent->id, 'user_id' => $request->user()->id, 'deposit_date' => $data['deposit_date'],
                'details' => Crypt::encryptString($data['details']), 'created_at' => now(),
            ]);
        });

        return back()->with('status', 'Evidencia independiente registrada por el administrador; no se modificó el pago.');
    }

    private function authorizeAdmin(): void
    {
        abort_unless(in_array(app(CompanyContext::class)->membership()->role, [MembershipRole::Owner, MembershipRole::Admin], true), 403);
    }
}
