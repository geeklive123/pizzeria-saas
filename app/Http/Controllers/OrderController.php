<?php

namespace App\Http\Controllers;

use App\Actions\AddConfiguredPizzaAction;
use App\Actions\AddOrderItemAction;
use App\Actions\CancelOrderAction;
use App\Actions\CancelOrderItemAction;
use App\Actions\CreateTakeawayOrderAction;
use App\Actions\DispatchOrderToKitchenWithPrintingAction;
use App\Actions\MarkOrderItemServedAction;
use App\Actions\PrintKitchenDispatchAction;
use App\Actions\UpdateConfiguredPizzaAction;
use App\Actions\UpdateOrderItemQuantityAction;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PrintAttemptStatus;
use App\Enums\ProductType;
use App\Http\Requests\AddOrderItemRequest;
use App\Http\Requests\CancelOrderItemRequest;
use App\Http\Requests\TakeawayOrderRequest;
use App\Http\Requests\UpdateOrderItemRequest;
use App\Models\KitchenDispatch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use App\Services\OrderPosCatalogService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class OrderController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', Order::class);
        $orders = Order::query()->forCompany($this->company())->forBranch($this->branch())
            ->whereIn('status', [OrderStatus::Open, OrderStatus::ReadyForPayment])
            ->with(['restaurantTable', 'createdBy'])->latest('opened_at')->get();

        return view('orders.index', compact('orders'));
    }

    public function createTakeaway(): View
    {
        Gate::authorize('create', Order::class);

        return view('orders.takeaway');
    }

    public function storeTakeaway(TakeawayOrderRequest $request, CreateTakeawayOrderAction $action): RedirectResponse
    {
        Gate::authorize('create', Order::class);
        $order = $action->execute($this->company(), $this->branch(), $request->user(), $request->validated());

        return redirect()->route('orders.show', $order->ulid)->with('success', "Pedido {$order->formattedNumber()} creado.");
    }

    public function show(string $order, OrderPosCatalogService $catalog): View
    {
        $order = $this->order($order)->load([
            'restaurantTable',
            'items' => fn ($query) => $query->with(['productVariant.product', 'sections.productVariant.product', 'modifiers.section'])->orderBy('created_at'),
            'kitchenDispatches' => fn ($query) => $query->with('printAttempts')->latest('sequence_number'),
        ]);
        Gate::authorize('view', $order);
        ['products' => $products, 'pizzaVariants' => $pizzaVariants, 'pizzaSizeKeys' => $pizzaSizeKeys, 'modifierOptions' => $modifierOptions] = $catalog->forOrderScreen($this->company(), $this->branch());

        $lastDispatch = $order->kitchenDispatches->first();

        return view('orders.show', compact('order', 'products', 'pizzaVariants', 'pizzaSizeKeys', 'modifierOptions', 'lastDispatch'));
    }

    public function addItem(AddOrderItemRequest $request, string $order, AddOrderItemAction $action, AddConfiguredPizzaAction $configuredPizza): RedirectResponse
    {
        $order = $this->order($order);
        Gate::authorize('update', $order);
        try {
            if ($request->filled('sections')) {
                $modifiers = collect($request->validated('modifiers', []))->filter(fn (array $modifier): bool => filled($modifier['option'] ?? null))->values()->all();
                $configuredPizza->execute($order, $request->validated('sections'), $request->validated('quantity'), $request->user(), $request->enum('fulfillment_type', OrderType::class), $modifiers, $request->validated('notes'));
            } else {
                $variant = ProductVariant::query()->forCompany($this->company())->with('product')
                    ->where('ulid', $request->validated('variant'))->firstOrFail();
                if ($variant->product->type === ProductType::Pizza) {
                    $configuredPizza->execute(
                        $order,
                        [['variant' => $variant->ulid]],
                        $request->validated('quantity'),
                        $request->user(),
                        $request->enum('fulfillment_type', OrderType::class),
                        notes: $request->validated('notes'),
                    );
                } else {
                    $action->execute($order, $variant, $request->validated('quantity'), $request->user(), $request->enum('fulfillment_type', OrderType::class), $request->validated('notes'));
                }
            }
        } catch (DomainException $exception) {
            return back()->withErrors(['item' => $exception->getMessage()]);
        }

        return back()->with('success', 'Producto agregado a la cuenta.');
    }

    public function updateItem(UpdateOrderItemRequest $request, string $order, string $item, UpdateOrderItemQuantityAction $action, UpdateConfiguredPizzaAction $configuredPizza): RedirectResponse
    {
        $order = $this->order($order);
        Gate::authorize('update', $order);
        $item = $this->item($order, $item);
        try {
            if ($request->filled('sections')) {
                $modifiers = collect($request->validated('modifiers', []))->filter(fn (array $modifier): bool => filled($modifier['option'] ?? null))->values()->all();
                $configuredPizza->execute($item, $request->validated('sections'), $request->validated('quantity'), $request->user(), OrderType::from($request->validated('fulfillment_type')), $modifiers, $request->validated('notes'));
            } else {
                $action->execute($item, $request->validated('quantity'), $request->user(), OrderType::from($request->validated('fulfillment_type')), $request->validated('notes'));
            }
        } catch (DomainException $exception) {
            return back()->withErrors(['item' => $exception->getMessage()]);
        }

        return back()->with('success', 'Ítem actualizado.');
    }

    public function dispatch(string $order, DispatchOrderToKitchenWithPrintingAction $action): RedirectResponse
    {
        $order = $this->order($order);
        Gate::authorize('update', $order);
        try {
            $result = $action->execute($order, request()->user());
        } catch (DomainException $exception) {
            return back()->withErrors(['order' => $exception->getMessage()]);
        }

        if ($result->printAttempt?->status === PrintAttemptStatus::Failed) {
            return back()->with('warning', 'El pedido fue enviado a cocina, pero no se pudo imprimir la comanda.');
        }

        return back()->with('success', $result->dispatch ? "Tanda #{$result->dispatch->sequence_number} enviada." : 'No hay productos nuevos para enviar.');
    }

    public function printKitchen(string $order, string $dispatch, PrintKitchenDispatchAction $action): RedirectResponse
    {
        $order = $this->order($order);
        Gate::authorize('update', $order);
        $dispatch = KitchenDispatch::query()->forCompany($this->company())->forBranch($this->branch())
            ->where('order_id', $order->getKey())->where('ulid', $dispatch)->firstOrFail();
        $attempt = $action->execute($dispatch, request()->user());

        return $attempt->status === PrintAttemptStatus::Failed
            ? back()->with('warning', 'No se pudo poner la comanda en cola. Revisa la configuración e inténtalo nuevamente.')
            : back()->with('success', 'Comanda pendiente de impresión.');
    }

    public function serveItem(string $order, string $item, MarkOrderItemServedAction $action): RedirectResponse
    {
        $order = $this->order($order);
        Gate::authorize('update', $order);
        $item = $this->item($order, $item);
        try {
            $action->execute($item, request()->user());
        } catch (DomainException $exception) {
            return back()->withErrors(['item' => $exception->getMessage()]);
        }

        return back()->with('success', 'Ítem marcado como servido.');
    }

    public function cancelItem(CancelOrderItemRequest $request, string $order, string $item, CancelOrderItemAction $action): RedirectResponse
    {
        $order = $this->order($order);
        Gate::authorize('update', $order);
        $item = $this->item($order, $item);
        try {
            $action->execute($item, $request->user(), $request->validated('reason'));
        } catch (DomainException $exception) {
            return back()->withErrors(['item' => $exception->getMessage()]);
        }

        return back()->with('success', 'Ítem cancelado y reservas liberadas.');
    }

    public function cancel(string $order, CancelOrderAction $action): RedirectResponse
    {
        $order = $this->order($order);
        Gate::authorize('cancel', $order);
        try {
            $action->execute($order, request()->user());
        } catch (DomainException $exception) {
            return back()->withErrors(['order' => $exception->getMessage()]);
        }

        return redirect()->route('orders.index')->with('success', 'Cuenta cancelada. El historial de cocina e inventario se conservó.');
    }

    private function order(string $ulid): Order
    {
        return Order::query()->forCompany($this->company())->forBranch($this->branch())->where('ulid', $ulid)->firstOrFail();
    }

    private function item(Order $order, string $ulid): OrderItem
    {
        return OrderItem::query()->where('company_id', $order->company_id)->where('branch_id', $order->branch_id)->where('order_id', $order->id)->where('ulid', $ulid)->firstOrFail();
    }
}
