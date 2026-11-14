<?php

namespace App\Http\Controllers;

use App\Models\OnlineOrderRequest;
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
        $filters = $request->validate(['status' => ['nullable', Rule::in(['pending_confirmation', 'confirmed', 'cancelled'])], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $query = OnlineOrderRequest::query()->forCompany($this->company())->where('branch_id', $this->branch()->id)->withSum('items', 'quantity');
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['from'])) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }

        return view('online-orders.index', ['orders' => $query->latest()->paginate(30)->withQueryString(), 'filters' => $filters]);
    }

    public function show(string $id): View
    {
        $order = $this->order($id)->load('items');
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
        DB::transaction(function () use ($id, $data, $request): void {
            $order = $this->order($id, true);
            Gate::authorize($data['status'] === 'cancelled' ? 'cancel' : 'update', $order);
            abort_unless($order->status === 'pending_confirmation', 409, 'La solicitud ya fue resuelta.');
            $order->update(['status' => $data['status'], 'resolved_by' => $request->user()->id, 'resolved_at' => now()]);
        });

        return redirect()->route('online-orders.show', $id);
    }

    private function order(string $id, bool $lock = false): OnlineOrderRequest
    {
        $query = OnlineOrderRequest::query()->forCompany($this->company())->where('branch_id', $this->branch()->id);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->findOrFail($id);
    }
}
