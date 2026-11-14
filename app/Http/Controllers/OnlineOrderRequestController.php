<?php

namespace App\Http\Controllers;

use App\Models\OnlineOrderRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OnlineOrderRequestController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', OnlineOrderRequest::class);
        $filters = $request->validate(['status' => ['nullable', Rule::in(['pending_confirmation', 'confirmed', 'cancelled'])], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])]]);
        $query = OnlineOrderRequest::query()->forCompany($this->company())->where('branch_id', $this->branch()->id)->where('is_fence', false)->withSum('items', 'quantity');
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
        $order = $this->order($id)->load(['items', 'resolvedBy']);
        Gate::authorize('view', $order);
        $phone = preg_replace('/\D+/', '', $order->customer_phone);
        if (strlen($phone) === 8) {
            $phone = '591'.$phone;
        }
        $message = 'Hola, te contactamos por tu solicitud '.$order->publicCode().' para coordinar la hora de recojo.';

        return view('online-orders.show', ['order' => $order, 'whatsapp' => 'https://wa.me/'.$phone.'?text='.rawurlencode($message)]);
    }

    public function transition(Request $request, string $id): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['confirmed', 'cancelled'])]]);
        $updated = DB::transaction(function () use ($id, $data, $request): bool {
            $order = $this->order($id, true);
            Gate::authorize($data['status'] === 'cancelled' ? 'cancel' : 'update', $order);
            if ($order->status !== 'pending_confirmation') {
                return false;
            }
            $order->update(['status' => $data['status'], 'resolved_by' => $request->user()->id, 'resolved_at' => now()]);

            return true;
        });

        $redirect = redirect()->route('online-orders.show', $id);

        return $updated ? $redirect : $redirect->with('online_order_notice', 'Esta solicitud ya fue atendida. Se conserva el estado actual.');
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
