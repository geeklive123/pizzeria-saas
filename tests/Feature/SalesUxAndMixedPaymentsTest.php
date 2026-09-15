<?php

namespace Tests\Feature;

use App\Actions\ClosePaidOrderAction;
use App\Actions\CreateTakeawayOrderAction;
use App\Actions\OpenCashSessionAction;
use App\Actions\RegisterPaymentAction;
use App\Enums\CashMovementType;
use App\Enums\MembershipRole;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\InventoryMovement;
use App\Models\Membership;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\RestaurantTable;
use App\Models\User;
use App\Services\OrderPaymentService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\MasaYManaMenuSeeder;
use DomainException;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesUxAndMixedPaymentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_pos_prioritizes_catalog_and_renders_each_live_flavor_selector_with_one_size_only(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(MasaYManaMenuSeeder::class);
        $company = Company::query()->where('name', 'Mi Pizzería')->firstOrFail();
        $branch = Branch::query()->where('company_id', $company->id)->where('name', 'Principal')->firstOrFail();
        $owner = Membership::query()->where('company_id', $company->id)->where('role', MembershipRole::Owner)
            ->with('user')->firstOrFail()->user;
        $order = app(CreateTakeawayOrderAction::class)->execute($company, $branch, $owner);

        $response = $this->actingInContext($owner, $company, $branch)->get(route('orders.show', $order->ulid))->assertOk();
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$response->getContent());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($dom);

        $composer = $xpath->query('//*[@data-pizza-composer]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $composer);
        $this->assertTrue($composer->hasAttribute('hidden'), 'El constructor debe iniciar cerrado para priorizar el catálogo.');

        $this->assertCount(0, $xpath->query('//*[@data-pizza-composer]//select[@data-pizza-size]'));
        $sizeState = $xpath->query('//*[@data-pizza-composer]//input[@type="hidden" and @data-pizza-size]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $sizeState);
        $this->assertSame('personal', $sizeState->getAttribute('value'));
        $sizeOptions = $xpath->query('//*[@data-pizza-composer]//input[@type="radio" and @data-pizza-size-option]');
        $this->assertCount(3, $sizeOptions);
        foreach (['personal', 'mediana', 'familiar'] as $index => $sizeKey) {
            $option = $sizeOptions->item($index);
            $this->assertInstanceOf(DOMElement::class, $option);
            $this->assertSame($sizeKey, $option->getAttribute('value'));
            $this->assertSame($index === 0, $option->hasAttribute('checked'));
        }

        $liveSelectors = $xpath->query('//*[@data-pizza-composer]//select[@data-pizza-variant]');
        $this->assertCount(4, $liveSelectors);
        foreach ($liveSelectors as $selector) {
            $sizeKeys = [];
            foreach ($xpath->query('./option[@data-size-key]', $selector) as $option) {
                $sizeKeys[] = $option->getAttribute('data-size-key');
            }
            $this->assertSame(['personal'], array_values(array_unique($sizeKeys)));
            $this->assertCount(17, $sizeKeys);
        }

        $templates = $xpath->query('//*[@data-pizza-options]');
        $this->assertCount(3, $templates);
        foreach ($templates as $template) {
            $expectedSize = $template->getAttribute('data-pizza-options');
            $options = $xpath->query('.//option[@data-size-key]', $template);
            $this->assertCount(17, $options);
            foreach ($options as $option) {
                $this->assertSame($expectedSize, $option->getAttribute('data-size-key'));
                $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', $option->getAttribute('data-price'));
            }
        }

        $this->assertCount(51, $xpath->query('//*[@data-open-pizza-composer]'));
        $this->assertGreaterThan(0, $xpath->query('//*[@data-pos-product and @data-product-kind!="pizza"]//form')->length);
        $response->assertSee('data-close-pizza-composer', false)
            ->assertSee('data-pizza-size-option', false)
            ->assertSee('Personal')
            ->assertSee('Mediana')
            ->assertSee('Grande')
            ->assertSee('data-pizza-size-key="personal"', false)
            ->assertSee('data-pizza-size-key="mediana"', false)
            ->assertSee('data-pizza-size-key="familiar"', false);
    }

    public function test_cash_30_plus_qr_70_completes_a_100_order_as_two_individual_payments(): void
    {
        [$company, $branch, $owner, $session] = $this->paymentContext();
        $table = RestaurantTable::factory()->for($branch)->create(['company_id' => $company->id]);
        $order = $this->servedOrder($company, $branch, $owner, '100.00', $table);
        $inventoryMovements = InventoryMovement::query()->count();

        app(RegisterPaymentAction::class)->execute($order, $session, PaymentMethod::Cash, '30.00', $owner, 'mix-cash-30', '30.00');
        $this->assertSame('30.00', app(OrderPaymentService::class)->paid($order));
        $this->assertSame('70.00', app(OrderPaymentService::class)->balance($order));
        $this->assertSame($table->id, $order->refresh()->active_restaurant_table_id);

        app(RegisterPaymentAction::class)->execute($order, $session, PaymentMethod::Qr, '70.00', $owner, 'mix-qr-70');

        $this->assertSame('100.00', app(OrderPaymentService::class)->paid($order));
        $this->assertSame('0.00', app(OrderPaymentService::class)->balance($order));
        $this->assertSame(OrderStatus::Paid, $order->refresh()->status);
        $this->assertNull($order->active_restaurant_table_id);
        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'method' => 'cash', 'amount' => 30]);
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'method' => 'qr', 'amount' => 70]);
        $this->assertDatabaseHas('cash_movements', ['cash_session_id' => $session->id, 'type' => CashMovementType::SaleCash->value, 'amount' => 30]);
        $this->assertSame($inventoryMovements, InventoryMovement::query()->count());
    }

    public function test_qr_20_cash_50_qr_30_updates_each_balance_and_releases_table_only_at_zero(): void
    {
        [$company, $branch, $owner, $session] = $this->paymentContext();
        $table = RestaurantTable::factory()->for($branch)->create(['company_id' => $company->id]);
        $order = $this->servedOrder($company, $branch, $owner, '100.00', $table);
        $item = $order->items()->firstOrFail();
        $inventoryMovements = InventoryMovement::query()->count();

        app(RegisterPaymentAction::class)->execute($order, $session, PaymentMethod::Qr, '20.00', $owner, 'sequence-qr-20');
        $this->assertSame('20.00', app(OrderPaymentService::class)->paid($order));
        $this->assertSame('80.00', app(OrderPaymentService::class)->balance($order));
        $this->assertSame($table->id, $order->refresh()->active_restaurant_table_id);

        app(RegisterPaymentAction::class)->execute($order, $session, PaymentMethod::Cash, '50.00', $owner, 'sequence-cash-50', '50.00');
        $this->assertSame('70.00', app(OrderPaymentService::class)->paid($order));
        $this->assertSame('30.00', app(OrderPaymentService::class)->balance($order));
        $this->assertSame($table->id, $order->refresh()->active_restaurant_table_id);

        app(RegisterPaymentAction::class)->execute($order, $session, PaymentMethod::Qr, '30.00', $owner, 'sequence-qr-30');
        $this->assertSame('100.00', app(OrderPaymentService::class)->paid($order));
        $this->assertSame('0.00', app(OrderPaymentService::class)->balance($order));
        $this->assertDatabaseCount('payments', 3);
        $this->assertSame(['qr', 'cash', 'qr'], Payment::query()->where('order_id', $order->id)->orderBy('id')->pluck('method')->map->value->all());
        $this->assertSame(OrderItemStatus::Served, $item->refresh()->status);
        $this->assertSame(OrderStatus::Paid, $order->refresh()->status);
        $this->assertNull($order->active_restaurant_table_id);
        $this->assertSame($inventoryMovements, InventoryMovement::query()->count());
    }

    public function test_cash_applies_70_from_100_received_and_records_30_as_change_only(): void
    {
        [$company, $branch, $owner, $session] = $this->paymentContext();
        $order = $this->servedOrder($company, $branch, $owner, '70.00');

        $payment = app(RegisterPaymentAction::class)->execute(
            $order,
            $session,
            PaymentMethod::Cash,
            '70.00',
            $owner,
            'cash-change-30',
            '100.00',
        );

        $this->assertSame('70.00', $payment->amount);
        $this->assertSame('100.00', $payment->received_amount);
        $this->assertSame('30.00', $payment->change_amount);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('cash_movements', ['type' => CashMovementType::SaleCash->value, 'amount' => 70]);
    }

    public function test_checkout_explains_mixed_payments_prefills_balance_and_rejects_overpayment_or_early_close(): void
    {
        [$company, $branch, $owner, $session] = $this->paymentContext();
        $order = $this->servedOrder($company, $branch, $owner, '100.00');
        app(RegisterPaymentAction::class)->execute($order, $session, PaymentMethod::Qr, '20.00', $owner, 'ux-qr-20');

        $this->actingInContext($owner, $company, $branch)
            ->get(route('orders.checkout', $order->ulid))
            ->assertOk()
            ->assertSee('Saldo restante')
            ->assertSee('combinar Efectivo + QR')
            ->assertSee('Siguiente monto sugerido: Bs 80,00')
            ->assertSee('Monto aplicado a la cuenta')
            ->assertSee('Efectivo recibido')
            ->assertSee('Cambio')
            ->assertSee('value="80.00" max="80.00"', false)
            ->assertSee('Historial individual para pagos simples, mixtos y parciales.');

        try {
            app(RegisterPaymentAction::class)->execute($order, $session, PaymentMethod::Qr, '80.01', $owner, 'over-balance');
            $this->fail('QR no debe superar el saldo pendiente.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('no puede superar el saldo', $exception->getMessage());
        }
        $this->assertDatabaseCount('payments', 1);

        try {
            app(ClosePaidOrderAction::class)->executeLocked($order->refresh());
            $this->fail('Una cuenta con saldo no debe cerrarse.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('saldo pendiente', $exception->getMessage());
        }
        $this->assertSame(OrderStatus::ReadyForPayment, $order->refresh()->status);
    }

    /** @return array{Company, Branch, User, CashSession} */
    private function paymentContext(): array
    {
        $company = Company::factory()->create();
        $branch = Branch::factory()->for($company)->create();
        $owner = User::factory()->create();
        Membership::factory()->for($company)->for($owner)->owner()->create();
        $register = CashRegister::query()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'name' => 'Caja Principal',
            'is_active' => true,
        ]);

        return [$company, $branch, $owner, app(OpenCashSessionAction::class)->execute($register, '0.00', $owner)];
    }

    private function servedOrder(Company $company, Branch $branch, User $owner, string $total, ?RestaurantTable $table = null): Order
    {
        $order = Order::factory()->for($branch)->create([
            'company_id' => $company->id,
            'restaurant_table_id' => $table?->id,
            'active_restaurant_table_id' => $table?->id,
            'type' => $table ? OrderType::DineIn : OrderType::Takeaway,
            'subtotal' => $total,
            'total' => $total,
            'created_by' => $owner->id,
        ]);
        OrderItem::factory()->for($order)->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'line_total' => $total,
            'unit_price' => $total,
            'status' => OrderItemStatus::Served,
            'served_at' => now(),
            'created_by' => $owner->id,
        ]);

        return $order;
    }

    private function actingInContext(User $user, Company $company, Branch $branch): static
    {
        return $this->actingAs($user)->withSession([
            'active_company_id' => $company->id,
            'active_branch_id' => $branch->id,
        ]);
    }
}
