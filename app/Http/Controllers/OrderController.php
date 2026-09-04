<?php

namespace App\Http\Controllers;

use App\Actions\AddConfiguredPizzaAction;
use App\Actions\AddOrderItemAction;
use App\Actions\AddPromotionToOrderAction;
use App\Actions\CancelOrderAction;
use App\Actions\CancelOrderItemAction;
use App\Actions\CreateTakeawayOrderAction;
use App\Actions\DispatchOrderToKitchenWithPrintingAction;
use App\Actions\FinalizePerBatchTableAction;
use App\Actions\MarkOrderItemServedAction;
use App\Actions\PrintKitchenDispatchAction;
use App\Actions\UpdateConfiguredPizzaAction;
use App\Actions\UpdateOrderCustomerAction;
use App\Actions\UpdateOrderItemQuantityAction;
use App\Enums\KitchenDispatchStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PrintAttemptStatus;
use App\Enums\ProductType;
use App\Http\Requests\AddOrderItemRequest;
use App\Http\Requests\AddPromotionRequest;
use App\Http\Requests\CancelOrderItemRequest;
use App\Http\Requests\DispatchOrderRequest;
use App\Http\Requests\OrderCustomerRequest;
use App\Http\Requests\TakeawayOrderRequest;
use App\Http\Requests\UpdateOrderItemRequest;
use App\Models\KitchenDispatch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Models\Promotion;
use App\Services\CurrentCashSessionService;
use App\Services\OrderFinancialService;
use App\Services\OrderHistoryService;
use App\Services\OrderPaymentService;
use App\Services\OrderPosCatalogService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

class OrderController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', Order::class);
        $orders = Order::query()->forCompany($this->company())->forBranch($this->branch())
            ->whereIn('status', [OrderStatus::Open, OrderStatus::ReadyForPayment])
            ->with(['restaurantTable', 'createdBy'])->latest('opened_at')->get();
        $paidOrders = Order::query()->forCompany($this->company())->forBranch($this->branch())
            ->where('status', OrderStatus::Paid)
            ->with(['restaurantTable', 'createdBy'])->latest('closed_at')
            ->paginate(50, ['*'], 'paid_page');

        return view('orders.index', compact('orders', 'paidOrders'));
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

    public function show(string $order, OrderPosCatalogService $catalog, OrderFinancialService $financials, OrderPaymentService $payments, CurrentCashSessionService $cashSessions, OrderHistoryService $history): View
    {
        $order = $this->order($order)->load([
            'restaurantTable',
            'items' => fn ($query) => $query->with(['productVariant.product', 'sections.productVariant.product', 'modifiers.section'])->orderBy('created_at'),
            'kitchenDispatches' => fn ($query) => $query->with('printAttempts')->latest('sequence_number'),
        ]);
        Gate::authorize('view', $order);
        ['products' => $products, 'promotions' => $promotions, 'pizzaVariants' => $pizzaVariants, 'pizzaSizeKeys' => $pizzaSizeKeys, 'modifierOptions' => $modifierOptions, 'toppingOptions' => $toppingOptions] = $catalog->forOrderScreen($this->company(), $this->branch());

        $lastDispatch = $order->kitchenDispatches->first();
        $draftFinancial = $financials->preview($order->items->where('status', OrderItemStatus::Draft));
        $pendingDispatch = $order->kitchenDispatches->firstWhere('status', KitchenDispatchStatus::AwaitingPayment);
        $pendingDispatch?->load(['items.orderItem.productVariant.product', 'items.orderItem.sections', 'payments.receivedBy']);
        $pendingPaid = $pendingDispatch ? $payments->dispatchPaid($pendingDispatch) : '0.00';
        $pendingBalance = $pendingDispatch ? $payments->dispatchBalance($pendingDispatch) : '0.00';
        $cashSession = $cashSessions->forUser($this->company(), $this->branch(), request()->user());
        $paymentClass = Payment::class;
        $idempotencyCash = (string) Str::ulid();
        $idempotencyQr = (string) Str::ulid();
        $idempotencyMixedCash = (string) Str::ulid();
        $idempotencyMixedQr = (string) Str::ulid();
        $orderPaid = $payments->paid($order);
        $orderBalance = $payments->balance($order);
        $orderHistory = $history->forOrder($order);

        return view('orders.show', compact('order', 'products', 'promotions', 'pizzaVariants', 'pizzaSizeKeys', 'modifierOptions', 'toppingOptions', 'lastDispatch', 'draftFinancial', 'pendingDispatch', 'pendingPaid', 'pendingBalance', 'cashSession', 'paymentClass', 'idempotencyCash', 'idempotencyQr', 'idempotencyMixedCash', 'idempotencyMixedQr', 'orderPaid', 'orderBalance', 'orderHistory'));
    }

    public function updateCustomer(OrderCustomerRequest $request, string $order, UpdateOrderCustomerAction $action): RedirectResponse
    {
        $order = $this->order($order);
        Gate::authorize('update', $order);
        $action->execute($order, $request->user(), $request->validated('customer_name'));

        return back()->with('success', 'Cliente actualizado.');
    }

    public function addPromotion(AddPromotionRequest $request, string $order, AddPromotionToOrderAction $action): RedirectResponse
    {
        $order = $this->order($order);
        Gate::authorize('update', $order);
        $promotion = Promotion::query()->forCompany($this->company())
            ->where('ulid', $request->validated('promotion'))->firstOrFail();
        try {
            $action->execute(
                $order,
                $promotion,
                $request->validated('quantity'),
                $request->user(),
                $request->enum('fulfillment_type', OrderType::class),
                $request->validated('notes'),
            );
        } catch (DomainException $exception) {
            return back()->withErrors(['item' => $exception->getMessage()]);
        }

        return back()->with('success', 'Promoción agregada a la cuenta.');
    }

    public function addItem(AddOrderItemRequest $request, string $order, AddOrderItemAction $action, AddConfiguredPizzaAction $configuredPizza): RedirectResponse
    {
        $order = $this->order($order);
        Gate::authorize('update', $order);
        try {
            if ($request->filled('sections')) {
                $modifiers = collect($request->validated('modifiers', []))->filter(fn (array $modifier): bool => filled($modifier['option'] ?? null))->values()->all();
                $configuredPizza->execute($order, $request->validated('sections'), $request->validated('quantity'), $request->user(), $request->enum('fulfillment_type', OrderType::class), $modifiers, $request->validated('notes'), $request->validated('toppings', []));
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
                        toppings: $request->validated('toppings', []),
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
                $configuredPizza->execute($item, $request->validated('sections'), $request->validated('quantity'), $request->user(), OrderType::from($request->validated('fulfillment_type')), $modifiers, $request->validated('notes'), $request->validated('toppings', []));
            } else {
                $action->execute($item, $request->validated('quantity'), $request->user(), OrderType::from($request->validated('fulfillment_type')), $request->validated('notes'));
            }
        } catch (DomainException $exception) {
            return back()->withErrors(['item' => $exception->getMessage()]);
        }

        return back()->with('success', 'Ítem actualizado.');
    }

    public function dispatch(DispatchOrderRequest $request, string $order, DispatchOrderToKitchenWithPrintingAction $action): RedirectResponse
    {
        $order = $this->order($order);
        Gate::authorize('update', $order);
        try {
            $result = $action->execute($order, $request->user(), $request->validated('discount_percentage'));
        } catch (DomainException $exception) {
            return back()->withErrors(['order' => $exception->getMessage()]);
        }

        if ($result->dispatch?->status === KitchenDispatchStatus::AwaitingPayment) {
            return redirect()->route('orders.show', $order->ulid)->with('success', "Tanda #{$result->dispatch->sequence_number} enviada a cocina. Registra el pago en este panel.");
        }
        if ($result->printAttempt?->status === PrintAttemptStatus::Failed) {
            return back()->with('warning', 'El pedido fue enviado a cocina, pero no se pudo imprimir la comanda.');
        }

        return back()->with('success', $result->dispatch ? "Tanda #{$result->dispatch->sequence_number} enviada." : 'No hay productos nuevos para enviar.');
    }

    public function finalizeTable(string $order, FinalizePerBatchTableAction $action): RedirectResponse
    {
        $order = $this->order($order);
        Gate::authorize('update', $order);
        try {
            $action->execute($order, request()->user());
        } catch (DomainException $exception) {
            return back()->withErrors(['order' => $exception->getMessage()]);
        }

        return redirect()->route('tables.index')->with('success', 'Mesa finalizada y liberada sin generar un nuevo cobro.');
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
