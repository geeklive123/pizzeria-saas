<?php

namespace Tests\Feature;

use App\Actions\DispatchOrderToKitchenAction;
use App\Actions\DispatchOrderToKitchenWithPrintingAction;
use App\Actions\OpenCashSessionAction;
use App\Actions\PrintKitchenDispatchAction;
use App\Actions\PrintOrderTicketAction;
use App\Enums\MembershipRole;
use App\Enums\ModifierOptionType;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PrinterPurpose;
use App\Enums\ProductType;
use App\Enums\TableChargeMode;
use App\Models\Branch;
use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\Company;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\KitchenDispatch;
use App\Models\Membership;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemModifier;
use App\Models\OrderItemSection;
use App\Models\Payment;
use App\Models\PrintAgent;
use App\Models\PrintAttempt;
use App\Models\PrinterSetting;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\RestaurantTable;
use App\Models\User;
use App\Printing\Contracts\ThermalPrinterTransport;
use App\Printing\Renderers\CustomerTicketRenderer;
use App\Printing\Renderers\KitchenCommandRenderer;
use App\Services\OrderPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class ThermalPrintingTest extends TestCase
{
    use RefreshDatabase;

    private RecordingThermalPrinterTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transport = new RecordingThermalPrinterTransport;
        $this->app->instance(ThermalPrinterTransport::class, $this->transport);
    }

    public function test_each_dispatch_prints_only_its_new_lines_and_reprint_does_not_duplicate_domain_state(): void
    {
        [$company, $branch, $owner] = $this->context();
        $this->printer($company, $branch, PrinterPurpose::Kitchen, auto: true);
        $table = RestaurantTable::factory()->for($branch)->create(['company_id' => $company->id, 'name' => 'MESA IMPRESIÓN']);
        $order = $this->order($company, $branch, $owner, $table, 'Javier');
        $firstItem = $this->simpleItem($order, $owner, 'Pizza Mediana', 'Mediana', '45.00');

        $first = app(DispatchOrderToKitchenWithPrintingAction::class)->execute($order, $owner);
        $this->assertNotNull($first->dispatch);
        $this->assertDatabaseCount('kitchen_dispatches', 1);
        $this->assertDatabaseCount('print_attempts', 1);
        $this->assertDatabaseHas('print_attempts', ['kitchen_dispatch_id' => $first->dispatch->id, 'status' => 'pending', 'is_reprint' => false]);
        $firstText = app(KitchenCommandRenderer::class)->render($first->dispatch)->plainText;
        $this->assertStringContainsString('PIZZA MEDIANA', $firstText);
        $duplicate = app(DispatchOrderToKitchenWithPrintingAction::class)->execute($order->refresh(), $owner);
        $this->assertNull($duplicate->dispatch);
        $this->assertDatabaseCount('print_attempts', 1);

        $secondItem = $this->simpleItem($order, $owner, 'Coca-Cola', '500 ml', '10.00');
        $thirdItem = $this->simpleItem($order, $owner, 'Pizza Familiar', 'Familiar', '87.00');
        $second = app(DispatchOrderToKitchenWithPrintingAction::class)->execute($order->refresh(), $owner);
        $secondText = app(KitchenCommandRenderer::class)->render($second->dispatch)->plainText;

        $this->assertDatabaseCount('kitchen_dispatches', 2);
        $this->assertDatabaseCount('print_attempts', 2);
        $this->assertStringContainsString('COCA-COLA 500 ML', $secondText);
        $this->assertStringContainsString('PIZZA FAMILIAR', $secondText);
        $this->assertStringNotContainsString('PIZZA MEDIANA', $secondText);
        $this->assertSame([$secondItem->id, $thirdItem->id], $second->dispatch->items()->orderBy('id')->pluck('order_item_id')->all());

        $before = [
            KitchenDispatch::query()->count(), InventoryReservation::query()->count(),
            InventoryMovement::query()->count(), $firstItem->refresh()->status,
        ];
        app(PrintKitchenDispatchAction::class)->execute($second->dispatch, $owner);
        $after = [
            KitchenDispatch::query()->count(), InventoryReservation::query()->count(),
            InventoryMovement::query()->count(), $firstItem->refresh()->status,
        ];

        $this->assertSame($before, $after);
        $this->assertDatabaseHas('print_attempts', ['kitchen_dispatch_id' => $second->dispatch->id, 'is_reprint' => true, 'status' => 'pending']);
    }

    public function test_per_batch_dispatch_prints_kitchen_before_payment_and_payment_does_not_print_it_again(): void
    {
        [$company, $branch, $owner] = $this->context();
        $this->printer($company, $branch, PrinterPurpose::Kitchen, auto: true);
        $this->printer($company, $branch, PrinterPurpose::CustomerTicket);
        $table = RestaurantTable::factory()->for($branch)->create(['company_id' => $company->id]);
        $order = $this->order($company, $branch, $owner, $table);
        $order->forceFill(['charge_mode' => TableChargeMode::PerBatch])->save();
        $this->simpleItem($order, $owner, 'Pizza', 'Mediana', '45.00');

        $result = app(DispatchOrderToKitchenWithPrintingAction::class)->execute($order, $owner);

        $this->assertSame('awaiting_payment', $result->dispatch->status->value);
        $this->assertDatabaseCount('kitchen_dispatches', 1);
        $this->assertDatabaseHas('print_attempts', [
            'kitchen_dispatch_id' => $result->dispatch->id,
            'purpose' => PrinterPurpose::Kitchen->value,
            'status' => 'pending',
            'is_reprint' => false,
        ]);

        $register = CashRegister::query()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'name' => 'Caja', 'is_active' => true]);
        app(OpenCashSessionAction::class)->execute($register, '0.00', $owner);
        $this->actingInContext($owner, $company, $branch)->post(route('orders.payments.store', $order->ulid), [
            'method' => PaymentMethod::Qr->value,
            'amount' => '45.00',
            'idempotency_key' => (string) Str::ulid(),
            'kitchen_dispatch' => $result->dispatch->ulid,
        ])->assertRedirect(route('orders.show', $order->ulid));

        $this->assertSame(1, $result->dispatch->printAttempts()->where('purpose', PrinterPurpose::Kitchen->value)->count());
        $this->assertSame(1, $result->dispatch->printAttempts()->where('purpose', PrinterPurpose::CustomerTicket->value)->count());
        $this->assertDatabaseCount('kitchen_dispatches', 1);
    }

    public function test_kitchen_document_handles_table_takeaway_one_to_four_flavors_notes_modifiers_and_no_prices(): void
    {
        [$company, $branch, $owner] = $this->context();
        $table = RestaurantTable::factory()->for($branch)->create(['company_id' => $company->id, 'name' => 'MESA 2']);
        $order = $this->order($company, $branch, $owner, $table);
        $flavorNames = ['CHAQUEÑA', 'LA CHURA', 'PATA NEGRA', 'DULCE FUEGO'];

        foreach ([1, 2, 3, 4] as $count) {
            $item = $this->pizzaItem($order, $owner, $count, $flavorNames, $count === 1 ? 'BIEN COCIDA' : null);
            if ($count === 1) {
                OrderItemModifier::query()->create([
                    'company_id' => $company->id, 'branch_id' => $branch->id, 'order_item_id' => $item->id,
                    'type' => ModifierOptionType::Add, 'name_snapshot' => 'Queso', 'price_delta_snapshot' => '5.00',
                ]);
                OrderItemModifier::query()->create([
                    'company_id' => $company->id, 'branch_id' => $branch->id, 'order_item_id' => $item->id,
                    'type' => ModifierOptionType::Remove, 'name_snapshot' => 'Cebolla', 'price_delta_snapshot' => '0.00',
                ]);
            }
        }

        $dispatch = app(DispatchOrderToKitchenAction::class)->execute($order, $owner);
        $text = app(KitchenCommandRenderer::class)->render($dispatch)->plainText;

        $this->assertStringContainsString('MESA 2', $text);
        $this->assertStringContainsString('CHAQUEÑA', $text);
        $this->assertStringContainsString('1/2 LA CHURA', $text);
        $this->assertStringContainsString('1/3 PATA NEGRA', $text);
        $this->assertStringContainsString('1/4 DULCE FUEGO', $text);
        $this->assertStringNotContainsString('1/1', $text);
        $this->assertStringContainsString("EXTRAS:\n+ QUESO", $text);
        $this->assertStringContainsString("QUITAR:\n- CEBOLLA", $text);
        $this->assertStringContainsString("OBS:\nBIEN COCIDA", $text);
        $this->assertStringContainsString('COMER AQUÍ', $text);
        $this->assertStringNotContainsString('Bs ', $text);
        $this->assertStringNotContainsString('size_key', $text);
        $this->assertStringNotContainsString('numerator', $text);

        $takeaway = $this->order($company, $branch, $owner, customer: 'Javier');
        $this->simpleItem($takeaway, $owner, 'Coca-Cola', '500 ml', '10.00');
        $takeawayDispatch = app(DispatchOrderToKitchenAction::class)->execute($takeaway, $owner);
        $takeawayText = app(KitchenCommandRenderer::class)->render($takeawayDispatch)->plainText;
        $this->assertStringContainsString('PARA LLEVAR', $takeawayText);
        $this->assertStringContainsString('Cliente: Javier', $takeawayText);
    }

    public function test_offline_agent_keeps_dispatch_pending_and_failed_job_retries_without_new_domain_records(): void
    {
        [$company, $branch, $owner] = $this->context();
        $this->printer($company, $branch, PrinterPurpose::Kitchen, auto: true);
        $table = RestaurantTable::factory()->for($branch)->create(['company_id' => $company->id, 'name' => 'MESA OFFLINE']);
        $order = $this->order($company, $branch, $owner, $table);
        $item = $this->simpleItem($order, $owner, 'Pizza', 'Familiar', '87.00');
        $this->actingInContext($owner, $company, $branch)
            ->post(route('orders.dispatch', $order->ulid))
            ->assertRedirect()->assertSessionHas('success', 'Tanda #1 enviada.');

        $dispatch = KitchenDispatch::query()->sole();
        $this->assertSame(OrderItemStatus::Sent, $item->refresh()->status);
        $this->assertDatabaseHas('print_attempts', ['kitchen_dispatch_id' => $dispatch->id, 'status' => 'pending']);
        $before = [KitchenDispatch::query()->count(), InventoryReservation::query()->count(), InventoryMovement::query()->count()];
        $dispatch->printAttempts()->sole()->update(['status' => 'failed', 'error_message' => 'Spooler offline', 'available_at' => now()]);

        $this->actingInContext($owner, $company, $branch)
            ->post(route('orders.kitchen.print', [$order->ulid, $dispatch->ulid]))
            ->assertRedirect()->assertSessionHas('success', 'Comanda pendiente de impresión.');

        $this->assertSame($before, [KitchenDispatch::query()->count(), InventoryReservation::query()->count(), InventoryMovement::query()->count()]);
        $this->assertSame(OrderItemStatus::Sent, $item->refresh()->status);
        $this->assertDatabaseCount('print_attempts', 1);
        $this->assertDatabaseHas('print_attempts', ['kitchen_dispatch_id' => $dispatch->id, 'status' => 'pending', 'error_message' => null]);
    }

    public function test_customer_ticket_covers_cash_qr_mixed_partial_change_and_snapshot_totals(): void
    {
        [$company, $branch, $owner] = $this->context();
        $register = CashRegister::query()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'name' => 'Caja', 'is_active' => true]);
        $session = app(OpenCashSessionAction::class)->execute($register, '0.00', $owner);
        $renderer = app(CustomerTicketRenderer::class);

        $cashOrder = $this->ticketOrder($company, $branch, $owner, '97.00', '7.00');
        $cashPayment = $this->payment($cashOrder, $session->id, $owner, PaymentMethod::Cash, '90.00', '100.00', '10.00');
        $cashText = $renderer->render($cashOrder, $owner)->plainText;
        $this->assertStringContainsString('SUBTOTAL', $cashText);
        $this->assertStringContainsString('Bs 97,00', $cashText);
        $this->assertStringContainsString('DESCUENTO', $cashText);
        $this->assertStringContainsString('Bs 7,00', $cashText);
        $this->assertStringContainsString('TOTAL', $cashText);
        $this->assertStringContainsString('Bs 90,00', $cashText);
        $this->assertStringContainsString('RECIBIDO', $cashText);
        $this->assertStringContainsString('Bs 100,00', $cashText);
        $this->assertStringContainsString('CAMBIO', $cashText);
        $this->assertStringContainsString('Bs 10,00', $cashText);

        $qrOrder = $this->ticketOrder($company, $branch, $owner, '100.00');
        $this->payment($qrOrder, $session->id, $owner, PaymentMethod::Qr, '100.00');
        $this->assertStringContainsString('QR', $renderer->render($qrOrder, $owner)->plainText);

        $mixedOrder = $this->ticketOrder($company, $branch, $owner, '100.00');
        $this->payment($mixedOrder, $session->id, $owner, PaymentMethod::Cash, '30.00', '30.00', '0.00');
        $this->payment($mixedOrder, $session->id, $owner, PaymentMethod::Qr, '70.00');
        $mixedText = $renderer->render($mixedOrder, $owner)->plainText;
        $this->assertStringContainsString('PAGOS', $mixedText);
        $this->assertStringContainsString('EFECTIVO', $mixedText);
        $this->assertStringContainsString('QR', $mixedText);
        $this->assertStringContainsString('PAGADO', $mixedText);
        $this->assertStringContainsString('SALDO', $mixedText);

        $partialOrder = $this->ticketOrder($company, $branch, $owner, '100.00');
        $this->payment($partialOrder, $session->id, $owner, PaymentMethod::Qr, '40.00');
        $partialText = $renderer->render($partialOrder, $owner)->plainText;
        $this->assertStringContainsString('Bs 40,00', $partialText);
        $this->assertStringContainsString('Bs 60,00', $partialText);
        $this->assertSame('60.00', app(OrderPaymentService::class)->balance($partialOrder));

        $cashPayment->order->items()->first()->productVariant()->update(['price' => '999.00']);
        $this->assertStringContainsString('Bs 90,00', $renderer->render($cashOrder, $owner)->plainText);
    }

    public function test_ticket_reprint_does_not_create_financial_or_inventory_records_or_change_balance(): void
    {
        [$company, $branch, $owner] = $this->context();
        $this->printer($company, $branch, PrinterPurpose::CustomerTicket, copies: 2);
        $register = CashRegister::query()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'name' => 'Caja', 'is_active' => true]);
        $session = app(OpenCashSessionAction::class)->execute($register, '0.00', $owner);
        $order = $this->ticketOrder($company, $branch, $owner, '100.00');
        $this->payment($order, $session->id, $owner, PaymentMethod::Cash, '30.00', '30.00', '0.00');
        $before = [Payment::query()->count(), CashMovement::query()->count(), InventoryMovement::query()->count(), app(OrderPaymentService::class)->balance($order)];

        app(PrintOrderTicketAction::class)->execute($order, $owner);
        app(PrintOrderTicketAction::class)->execute($order, $owner);

        $this->assertSame($before, [Payment::query()->count(), CashMovement::query()->count(), InventoryMovement::query()->count(), app(OrderPaymentService::class)->balance($order)]);
        $this->assertDatabaseCount('print_attempts', 2);
        $this->assertDatabaseHas('print_attempts', ['order_id' => $order->id, 'is_reprint' => true]);
        $this->assertSame(2, $order->printAttempts()->oldest('attempted_at')->firstOrFail()->copies);
    }

    public function test_owner_and_admin_configure_branch_printers_while_waiter_cannot_and_test_uses_selected_printer_and_copies(): void
    {
        [$company, $branch, $owner] = $this->context();
        $admin = User::factory()->create();
        Membership::factory()->for($company)->for($admin)->create(['role' => MembershipRole::Admin]);
        $waiter = User::factory()->create();
        Membership::factory()->for($company)->for($waiter)->create(['role' => MembershipRole::Waiter]);
        $payload = $this->settingsPayload($company, $branch, 'EPSON Cocina', 'EPSON Tickets', 3);

        $this->actingInContext($admin, $company, $branch)->put(route('settings.update'), $payload)->assertRedirect();
        $this->assertDatabaseHas('printer_settings', ['purpose' => 'kitchen', 'windows_printer_name' => 'EPSON Cocina', 'copies' => 3]);
        $this->assertDatabaseHas('printer_settings', ['purpose' => 'customer_ticket', 'windows_printer_name' => 'EPSON Tickets']);

        $same = $payload;
        $same['printers']['customer_ticket']['windows_printer_name'] = 'EPSON Cocina';
        $this->actingInContext($owner, $company, $branch)->put(route('settings.update'), $same)->assertRedirect();
        $this->assertSame(2, PrinterSetting::query()->where('windows_printer_name', 'EPSON Cocina')->count());

        $this->actingInContext($waiter, $company, $branch)->put(route('settings.update'), $payload)->assertForbidden();
        $this->actingInContext($owner, $company, $branch)
            ->post(route('settings.printers.test', PrinterPurpose::Kitchen->value))->assertRedirect()->assertSessionHas('success');
        $attempt = PrintAttempt::query()->sole();
        $this->assertSame('EPSON Cocina', $attempt->windows_printer_name);
        $this->assertSame(3, $attempt->copies);
        $this->assertStringContainsString('PRUEBA DE IMPRESIÓN', $this->decode((string) base64_decode($attempt->document_payload, true)));
    }

    public function test_operational_roles_see_agent_status_but_cannot_edit_settings(): void
    {
        [$company, $branch, $owner] = $this->context();
        $payload = $this->settingsPayload($company, $branch, 'EPSON Cocina', 'EPSON Tickets', 1);
        $agent = PrintAgent::query()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'name' => 'Agente sensible',
            'token_hash' => hash('sha256', 'token-que-no-debe-mostrarse'),
            'is_active' => true,
            'last_seen_at' => now(),
        ]);
        $users = collect([MembershipRole::Cashier, MembershipRole::Waiter, MembershipRole::Kitchen])
            ->map(function (MembershipRole $role) use ($company): User {
                $user = User::factory()->create();
                Membership::factory()->for($company)->for($user)->create(['role' => $role]);

                return $user;
            });

        foreach ($users as $user) {
            $this->actingInContext($user, $company, $branch)->get(route('dashboard'))
                ->assertOk()
                ->assertSee('Impresora')
                ->assertSee('En línea')
                ->assertDontSee('Agente sensible')
                ->assertDontSee('token-que-no-debe-mostrarse');
            $this->actingInContext($user, $company, $branch)->get(route('settings.edit'))->assertForbidden();
            $this->actingInContext($user, $company, $branch)->put(route('settings.update'), $payload)->assertForbidden();
        }
        $this->actingInContext($owner, $company, $branch)->get(route('settings.edit'))
            ->assertOk()
            ->assertSee('En línea');

        $agent->update(['last_seen_at' => now()->subMinutes(2)]);
        $otherCompany = Company::factory()->create();
        $otherBranch = Branch::factory()->for($otherCompany)->create();
        PrintAgent::query()->create([
            'company_id' => $otherCompany->id,
            'branch_id' => $otherBranch->id,
            'name' => 'Agente de otra empresa',
            'token_hash' => hash('sha256', 'token-externo'),
            'is_active' => true,
            'last_seen_at' => now(),
        ]);

        foreach ($users as $user) {
            $this->actingInContext($user, $company, $branch)->get(route('dashboard'))
                ->assertOk()
                ->assertSee('Fuera de línea');
        }
        $this->actingInContext($owner, $company, $branch)->get(route('settings.edit'))
            ->assertOk()
            ->assertSee('Fuera de línea');
    }

    public function test_waiter_can_reprint_kitchen_but_cannot_print_financial_ticket(): void
    {
        [$company, $branch, $owner] = $this->context();
        $waiter = User::factory()->create();
        Membership::factory()->for($company)->for($waiter)->create(['role' => MembershipRole::Waiter]);
        $this->printer($company, $branch, PrinterPurpose::Kitchen);
        $this->printer($company, $branch, PrinterPurpose::CustomerTicket);
        $order = $this->order($company, $branch, $owner);
        $this->simpleItem($order, $owner, 'Pizza', 'Mediana', '50.00');
        $dispatch = app(DispatchOrderToKitchenAction::class)->execute($order, $owner);

        $this->actingInContext($waiter, $company, $branch)
            ->post(route('orders.kitchen.print', [$order->ulid, $dispatch->ulid]))->assertRedirect();
        $this->actingInContext($waiter, $company, $branch)
            ->post(route('orders.ticket.print', $order->ulid))->assertForbidden();
    }

    private function context(): array
    {
        $company = Company::factory()->create(['name' => 'Masa & Maña']);
        $branch = Branch::factory()->for($company)->create();
        $owner = User::factory()->create(['name' => 'Daniela']);
        Membership::factory()->for($company)->for($owner)->owner()->create();

        return [$company, $branch, $owner];
    }

    private function order(Company $company, Branch $branch, User $user, ?RestaurantTable $table = null, ?string $customer = null): Order
    {
        return Order::factory()->for($branch)->create([
            'company_id' => $company->id, 'restaurant_table_id' => $table?->id,
            'active_restaurant_table_id' => $table?->id, 'type' => $table ? OrderType::DineIn : OrderType::Takeaway,
            'customer_name' => $customer, 'customer_phone' => $customer ? '74818256' : null, 'created_by' => $user->id,
        ]);
    }

    private function simpleItem(Order $order, User $user, string $productName, string $variantName, string $price): OrderItem
    {
        $storedName = Product::query()->where('company_id', $order->company_id)->where('name', $productName)->exists()
            ? $productName.' '.$order->id
            : $productName;
        $product = Product::factory()->create(['company_id' => $order->company_id, 'name' => $storedName, 'type' => ProductType::Other]);
        $variant = ProductVariant::factory()->for($product)->create(['company_id' => $order->company_id, 'name' => $variantName, 'price' => $price]);

        return OrderItem::factory()->for($order)->create([
            'company_id' => $order->company_id, 'branch_id' => $order->branch_id, 'product_variant_id' => $variant->id,
            'unit_price' => $price, 'line_total' => $price, 'fulfillment_type' => $order->type, 'created_by' => $user->id,
        ]);
    }

    private function pizzaItem(Order $order, User $user, int $count, array $flavors, ?string $notes = null): OrderItem
    {
        $baseProduct = Product::factory()->create(['company_id' => $order->company_id, 'name' => 'Pizza configurada '.$count, 'type' => ProductType::Pizza]);
        $baseVariant = ProductVariant::factory()->for($baseProduct)->create(['company_id' => $order->company_id, 'name' => 'Familiar', 'size_key' => 'familiar', 'price' => '87.00']);
        $item = OrderItem::factory()->for($order)->create([
            'company_id' => $order->company_id, 'branch_id' => $order->branch_id, 'product_variant_id' => $baseVariant->id,
            'unit_price' => '87.00', 'line_total' => '87.00', 'fulfillment_type' => $order->type, 'notes' => $notes, 'created_by' => $user->id,
        ]);
        for ($position = 1; $position <= $count; $position++) {
            $flavorProduct = Product::factory()->create(['company_id' => $order->company_id, 'name' => $flavors[$position - 1].' '.$count, 'type' => ProductType::Pizza]);
            $flavorVariant = ProductVariant::factory()->for($flavorProduct)->create(['company_id' => $order->company_id, 'name' => 'Familiar', 'size_key' => 'familiar', 'price' => '87.00']);
            OrderItemSection::query()->create([
                'company_id' => $order->company_id, 'branch_id' => $order->branch_id, 'order_item_id' => $item->id,
                'product_variant_id' => $flavorVariant->id, 'fraction_numerator' => 1, 'fraction_denominator' => $count,
                'position' => $position, 'unit_price_snapshot' => '87.00', 'product_name_snapshot' => $flavors[$position - 1],
                'variant_name_snapshot' => 'Familiar',
            ]);
        }

        return $item;
    }

    private function ticketOrder(Company $company, Branch $branch, User $user, string $subtotal, string $discount = '0.00'): Order
    {
        $total = bcsub($subtotal, $discount, 2);
        $order = $this->order($company, $branch, $user);
        $order->forceFill(['status' => OrderStatus::ReadyForPayment, 'subtotal' => $subtotal, 'discount_total' => $discount, 'total' => $total])->save();
        $item = $this->simpleItem($order, $user, 'Cachetona', 'Familiar', $total);
        $item->forceFill(['status' => OrderItemStatus::Served, 'unit_price' => $total, 'line_total' => $total, 'served_at' => now()])->save();

        return $order->refresh();
    }

    private function payment(Order $order, int $sessionId, User $user, PaymentMethod $method, string $amount, ?string $received = null, ?string $change = null): Payment
    {
        return Payment::query()->create([
            'company_id' => $order->company_id, 'branch_id' => $order->branch_id, 'order_id' => $order->id,
            'cash_session_id' => $sessionId, 'method' => $method, 'amount' => $amount, 'received_amount' => $received,
            'change_amount' => $change, 'paid_at' => now(), 'received_by' => $user->id, 'status' => PaymentStatus::Completed,
            'idempotency_key' => (string) Str::ulid(),
        ]);
    }

    private function printer(Company $company, Branch $branch, PrinterPurpose $purpose, bool $auto = false, int $copies = 1): PrinterSetting
    {
        return PrinterSetting::query()->create([
            'company_id' => $company->id, 'branch_id' => $branch->id, 'purpose' => $purpose,
            'windows_printer_name' => 'Impresora de prueba', 'is_active' => true, 'auto_print' => $auto,
            'copies' => $copies, 'paper_width_mm' => 80,
        ]);
    }

    private function settingsPayload(Company $company, Branch $branch, string $kitchen, string $ticket, int $copies): array
    {
        return [
            'company' => ['name' => $company->name, 'legal_name' => null, 'tax_id' => null, 'phone' => null, 'email' => null],
            'branch' => ['name' => $branch->name, 'address' => null, 'phone' => null],
            'printers' => [
                'kitchen' => ['windows_printer_name' => $kitchen, 'is_active' => 1, 'auto_print' => 1, 'copies' => $copies],
                'customer_ticket' => ['windows_printer_name' => $ticket, 'is_active' => 1, 'copies' => 1],
            ],
        ];
    }

    private function actingInContext(User $user, Company $company, Branch $branch): static
    {
        return $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id]);
    }

    private function decode(string $document): string
    {
        return iconv('CP850', 'UTF-8//IGNORE', $document) ?: '';
    }
}

class RecordingThermalPrinterTransport implements ThermalPrinterTransport
{
    public bool $fail = false;

    public array $documents = [];

    public function send(string $printerName, string $document, int $copies): void
    {
        if ($this->fail) {
            throw new RuntimeException('Spooler offline');
        }
        $this->documents[] = ['printer' => $printerName, 'document' => $document, 'copies' => $copies];
    }
}
