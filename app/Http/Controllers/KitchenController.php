<?php

namespace App\Http\Controllers;

use App\Actions\MarkKitchenItemReadyAction;
use App\Actions\StartKitchenItemAction;
use App\Enums\OrderItemStatus;
use App\Models\KitchenDispatch;
use App\Models\OrderItem;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class KitchenController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', KitchenDispatch::class);
        $dispatches = KitchenDispatch::query()
            ->forCompany($this->company())
            ->forBranch($this->branch())
            ->whereHas('items.orderItem', fn ($query) => $query
                ->where('requires_preparation', true)
                ->whereIn('status', [OrderItemStatus::Sent, OrderItemStatus::Preparing, OrderItemStatus::Ready]))
            ->with([
                'order.restaurantTable',
                'items.orderItem.productVariant.product',
                'items.orderItem.sections',
                'items.orderItem.modifiers.section',
            ])
            ->orderBy('dispatched_at')
            ->get();

        return view('kitchen.index', compact('dispatches'));
    }

    public function start(string $item, StartKitchenItemAction $action): RedirectResponse
    {
        $item = $this->item($item);
        try {
            $action->execute($item, request()->user());
        } catch (DomainException $exception) {
            return back()->withErrors(['kitchen' => $exception->getMessage()]);
        }

        return back()->with('success', 'Preparación iniciada.');
    }

    public function ready(string $item, MarkKitchenItemReadyAction $action): RedirectResponse
    {
        $item = $this->item($item);
        try {
            $action->execute($item, request()->user());
        } catch (DomainException $exception) {
            return back()->withErrors(['kitchen' => $exception->getMessage()]);
        }

        return back()->with('success', 'Producto marcado como listo.');
    }

    private function item(string $ulid): OrderItem
    {
        return OrderItem::query()->forCompany($this->company())
            ->where('branch_id', $this->branch()->getKey())
            ->where('ulid', $ulid)
            ->firstOrFail();
    }
}
