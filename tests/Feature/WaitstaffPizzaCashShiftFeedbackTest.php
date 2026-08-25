<?php

namespace Tests\Feature;

use App\Actions\CloseCashSessionAction;
use App\Actions\CreateTakeawayOrderAction;
use App\Actions\ManualCashMovementAction;
use App\Actions\OpenCashSessionAction;
use App\Actions\OwnerCashWithdrawalAction;
use App\Actions\RegisterPaymentAction;
use App\Enums\CashMovementType;
use App\Enums\MembershipRole;
use App\Enums\OrderItemStatus;
use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\Company;
use App\Models\Expense;
use App\Models\InventoryMovement;
use App\Models\Membership;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\CashSessionSummaryService;
use App\Services\PizzaCompositionService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\MasaYManaMenuSeeder;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WaitstaffPizzaCashShiftFeedbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_personal_rejects_combination_while_mediana_and_familiar_accept_one_to_four_size_compatible_flavors(): void
    {
        [$company, $branch, $owner] = $this->menuContext();
        $service = app(PizzaCompositionService::class);
        $personal = ProductVariant::query()->forCompany($company)->where('size_key', 'personal')->where('is_active', true)
            ->whereHas('product', fn ($query) => $query->where('type', 'pizza'))->take(2)->get();

        try {
            $service->compose($company, $personal->map(fn (ProductVariant $variant): array => ['variant' => $variant->ulid])->all(), [], OrderType::DineIn);
            $this->fail('Personal no debe aceptar combinación.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('Personal no permite combinar', $exception->getMessage());
        }

        foreach (['mediana', 'familiar'] as $sizeKey) {
            $variants = ProductVariant::query()->forCompany($company)->where('size_key', $sizeKey)->where('is_active', true)
                ->whereHas('product', fn ($query) => $query->where('type', 'pizza'))->take(4)->get();
            for ($count = 1; $count <= 4; $count++) {
                $result = $service->compose(
                    $company,
                    $variants->take($count)->map(fn (ProductVariant $variant): array => ['variant' => $variant->ulid])->all(),
                    [],
                    OrderType::DineIn,
                );
                $this->assertCount($count, $result['sections']);
                $this->assertSame($sizeKey, $result['snapshot']['size_key']);
            }
        }
    }

    public function test_pos_starts_with_normal_single_flavor_sale_and_keeps_strict_size_sources_and_direct_beverages(): void
    {
        [$company, $branch, $owner] = $this->menuContext();
        $order = app(CreateTakeawayOrderAction::class)->execute($company, $branch, $owner);
        $response = $this->actingInContext($owner, $company, $branch)
            ->get(route('orders.show', $order->ulid))
            ->assertOk()
            ->assertSee('La venta normal inicia como pizza completa de un solo sabor')
            ->assertSee('+ Combinar sabores');

        $html = $response->getContent();
        $this->assertMatchesRegularExpression('/data-pizza-combine-toggle[^>]*hidden/', $html);
        foreach (['personal', 'mediana', 'familiar'] as $sizeKey) {
            preg_match('/<template data-pizza-options="'.preg_quote($sizeKey, '/').'">(.*?)<\/template>/s', $html, $match);
            $this->assertNotEmpty($match);
            $this->assertStringContainsString('data-size-key="'.$sizeKey.'"', $match[1]);
            foreach (array_diff(['personal', 'mediana', 'familiar'], [$sizeKey]) as $wrongSize) {
                $this->assertStringNotContainsString('data-size-key="'.$wrongSize.'"', $match[1]);
            }
        }
        $javascript = file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString('resetCombination();', $javascript);
        $this->assertStringContainsString("['mediana', 'familiar'].includes(size.value)", $javascript);

        $pizza = ProductVariant::query()->forCompany($company)->where('size_key', 'personal')->where('is_active', true)
            ->whereHas('product', fn ($query) => $query->where('type', 'pizza'))->firstOrFail();
        $this->actingInContext($owner, $company, $branch)->post(route('orders.items.store', $order->ulid), [
            'variant' => $pizza->ulid,
            'quantity' => '1',
            'fulfillment_type' => 'takeaway',
        ])->assertRedirect();
        $pizzaItem = $order->items()->latest('id')->firstOrFail();
        $this->assertCount(1, $pizzaItem->sections);
        $this->assertSame('personal', $pizzaItem->configuration_snapshot['size_key']);

        $this->assertMatchesRegularExpression('/data-product-kind="beverage".*?<form[^>]+orders\/.*?\/items/s', $html);
    }

    public function test_required_shift_example_separates_cash_qr_owner_withdrawal_and_closing_difference(): void
    {
        [$company, $branch, $owner, , $cashier, , $register] = $this->cashContext();
        $session = app(OpenCashSessionAction::class)->execute($register, '1000.00', $cashier);
        $this->assertNull($session->inherited_cash_amount);
        $this->assertSame('0.00', $session->opening_difference_amount);
        $this->assertSame('1000.00', $session->expected_cash_amount);
        $cashOrder = $this->servedOrder($company, $branch, $cashier, '500.00');
        $qrOrder = $this->servedOrder($company, $branch, $cashier, '500.00');

        app(RegisterPaymentAction::class)->execute($cashOrder, $session, PaymentMethod::Cash, '500.00', $cashier, 'required-cash', '500.00');
        app(RegisterPaymentAction::class)->execute($qrOrder, $session, PaymentMethod::Qr, '500.00', $cashier, 'required-qr');
        app(OwnerCashWithdrawalAction::class)->execute($session, '500.00', 'Retiro de utilidades', 'Entrega al propietario', $owner, 'required-withdrawal');

        $summary = app(CashSessionSummaryService::class)->calculate($session);
        $this->assertSame('1000.00', $summary['sales_total']);
        $this->assertSame('500.00', $summary['cash_payments']);
        $this->assertSame('500.00', $summary['qr_payments']);
        $this->assertSame('500.00', $summary['owner_withdrawals']);
        $this->assertSame('1000.00', $summary['expected_cash']);
        $this->assertDatabaseCount('expenses', 0);
        $this->assertSame(0, Expense::query()->count());
        $this->actingInContext($cashier, $company, $branch)->get(route('cash.index'))
            ->assertOk()
            ->assertSee('Ventas efectivo')
            ->assertSee('Ventas QR')
            ->assertSee('Retiros propietario')
            ->assertSee('Efectivo esperado');

        $closed = app(CloseCashSessionAction::class)->execute($session, '980.00', $cashier, 'Faltante contado');
        $this->assertSame('1000.00', $closed->expected_cash_amount);
        $this->assertSame('980.00', $closed->counted_cash_amount);
        $this->assertSame('-20.00', $closed->difference_amount);
        $this->assertDatabaseCount('payments', 2);
        $this->assertSame('1000.00', app(CashSessionSummaryService::class)->calculate($closed)['sales_total']);
        $this->assertDatabaseCount('cash_movements', 3);
    }

    public function test_occupied_register_identifies_cashier_and_owner_admin_only_can_authorize_withdrawals(): void
    {
        [$company, $branch, $owner, $admin, $cashier, $secondCashier, $register] = $this->cashContext();
        $session = app(OpenCashSessionAction::class)->execute($register, '300.00', $cashier);

        try {
            app(OpenCashSessionAction::class)->execute($register, '0.00', $secondCashier);
            $this->fail('La misma caja no puede tener dos turnos abiertos.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString($cashier->name, $exception->getMessage());
            $this->assertStringContainsString('desde', $exception->getMessage());
        }
        $this->assertDatabaseCount('cash_sessions', 1);

        try {
            app(OwnerCashWithdrawalAction::class)->execute($session, '10.00', 'No autorizado', null, $cashier, 'cashier-denied');
            $this->fail('Una cajera no debe autorizar un retiro privilegiado.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('cash_movements', 1);
        }

        $adminWithdrawal = app(OwnerCashWithdrawalAction::class)->execute($session, '100.00', 'Retiro autorizado', 'Admin autoriza', $admin, 'admin-withdrawal');
        $duplicate = app(OwnerCashWithdrawalAction::class)->execute($session, '100.00', 'Retiro autorizado', 'Admin autoriza', $admin, 'admin-withdrawal');
        $this->assertSame($adminWithdrawal->id, $duplicate->id);
        $this->assertSame($admin->id, $adminWithdrawal->authorized_by);
        $this->assertSame('200.00', app(CashSessionSummaryService::class)->calculate($session)['expected_cash']);
        $this->assertDatabaseCount('expenses', 0);

        $ownerWithdrawal = app(OwnerCashWithdrawalAction::class)->execute($session, '50.00', 'Segundo retiro', null, $owner, 'owner-withdrawal');
        $this->assertSame($owner->id, $ownerWithdrawal->authorized_by);
    }

    public function test_handover_uses_previous_counted_cash_but_never_inherits_qr_or_changes_previous_close(): void
    {
        [$company, $branch, , , $cashier, $secondCashier, $register] = $this->cashContext();
        $first = app(OpenCashSessionAction::class)->execute($register, '1000.00', $cashier);
        $order = $this->servedOrder($company, $branch, $cashier, '500.00');
        app(RegisterPaymentAction::class)->execute($order, $first, PaymentMethod::Qr, '500.00', $cashier, 'handover-qr');
        $first = app(CloseCashSessionAction::class)->execute($first, '1000.00', $cashier);
        $closedSnapshot = $first->only(['expected_cash_amount', 'counted_cash_amount', 'difference_amount']);
        $closedAt = $first->closed_at->toISOString();
        $this->actingInContext($secondCashier, $company, $branch)->get(route('cash.open.form'))
            ->assertOk()
            ->assertSee('Referencia heredada del turno anterior')
            ->assertSee('Bs 1.000,00')
            ->assertSee('QR inicial: Bs 0,00');

        $second = app(OpenCashSessionAction::class)->execute($register, '980.00', $secondCashier);
        $summary = app(CashSessionSummaryService::class)->calculate($second);

        $this->assertSame($first->id, $second->previous_cash_session_id);
        $this->assertSame('1000.00', $second->inherited_cash_amount);
        $this->assertSame('980.00', $second->opening_amount);
        $this->assertSame('-20.00', $second->opening_difference_amount);
        $this->assertSame('0.00', $summary['qr_payments']);
        $this->assertSame('980.00', $summary['expected_cash']);
        $this->assertSame($closedSnapshot, $first->fresh()->only(array_keys($closedSnapshot)));
        $this->assertSame($closedAt, $first->fresh()->closed_at->toISOString());
        $this->actingInContext($secondCashier, $company, $branch)->get(route('cash.index'))
            ->assertOk()
            ->assertSee('Referencia heredada')
            ->assertSee('Diferencia al recibir: Bs -20,00');
    }

    public function test_closed_shift_rejects_every_new_movement(): void
    {
        [, , $owner, , $cashier, , $register] = $this->cashContext();
        $session = app(OpenCashSessionAction::class)->execute($register, '100.00', $cashier);
        $closed = app(CloseCashSessionAction::class)->execute($session, '100.00', $cashier);

        try {
            app(ManualCashMovementAction::class)->execute($closed, CashMovementType::ManualIn, '10.00', 'Tarde', $owner);
            $this->fail('Un turno cerrado no acepta movimientos.');
        } catch (DomainException) {
            $this->assertDatabaseCount('cash_movements', 1);
        }

        $this->expectException(DomainException::class);
        app(OwnerCashWithdrawalAction::class)->execute($closed, '10.00', 'Tarde', null, $owner, 'closed-withdrawal');
    }

    public function test_all_payment_orders_and_mixed_sequences_keep_individual_records_and_only_cash_physical(): void
    {
        [$company, $branch, , , $cashier, , $register] = $this->cashContext();
        $session = app(OpenCashSessionAction::class)->execute($register, '0.00', $cashier);
        $inventoryBefore = InventoryMovement::query()->count();

        $cashOnly = $this->servedOrder($company, $branch, $cashier, '100.00');
        app(RegisterPaymentAction::class)->execute($cashOnly, $session, PaymentMethod::Cash, '100.00', $cashier, 'cash-only', '100.00');
        $qrOnly = $this->servedOrder($company, $branch, $cashier, '100.00');
        app(RegisterPaymentAction::class)->execute($qrOnly, $session, PaymentMethod::Qr, '100.00', $cashier, 'qr-only');
        $mixed = $this->servedOrder($company, $branch, $cashier, '100.00');
        app(RegisterPaymentAction::class)->execute($mixed, $session, PaymentMethod::Cash, '30.00', $cashier, 'mix-30', '30.00');
        app(RegisterPaymentAction::class)->execute($mixed, $session, PaymentMethod::Qr, '70.00', $cashier, 'mix-70');
        $partial = $this->servedOrder($company, $branch, $cashier, '100.00');
        app(RegisterPaymentAction::class)->execute($partial, $session, PaymentMethod::Qr, '20.00', $cashier, 'partial-20');
        app(RegisterPaymentAction::class)->execute($partial, $session, PaymentMethod::Cash, '50.00', $cashier, 'partial-50', '50.00');
        app(RegisterPaymentAction::class)->execute($partial, $session, PaymentMethod::Qr, '30.00', $cashier, 'partial-30');

        $summary = app(CashSessionSummaryService::class)->calculate($session);
        $this->assertSame('180.00', $summary['cash_payments']);
        $this->assertSame('220.00', $summary['qr_payments']);
        $this->assertSame('180.00', $summary['expected_cash']);
        $this->assertDatabaseCount('payments', 7);
        $this->assertSame(['cash', 'qr'], Payment::query()->where('order_id', $mixed->id)->orderBy('id')->pluck('method')->map->value->all());
        $this->assertSame(['qr', 'cash', 'qr'], Payment::query()->where('order_id', $partial->id)->orderBy('id')->pluck('method')->map->value->all());
        $this->assertSame($inventoryBefore, InventoryMovement::query()->count());
    }

    private function menuContext(): array
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(MasaYManaMenuSeeder::class);
        $company = Company::query()->where('name', 'Mi Pizzería')->firstOrFail();
        $branch = Branch::query()->where('company_id', $company->id)->where('name', 'Principal')->firstOrFail();
        $owner = Membership::query()->where('company_id', $company->id)->where('role', MembershipRole::Owner)
            ->with('user')->firstOrFail()->user;

        return [$company, $branch, $owner];
    }

    private function cashContext(): array
    {
        $company = Company::factory()->create();
        $branch = Branch::factory()->for($company)->create();
        $owner = $this->member($company, MembershipRole::Owner);
        $admin = $this->member($company, MembershipRole::Admin);
        $cashier = $this->member($company, MembershipRole::Cashier);
        $secondCashier = $this->member($company, MembershipRole::Cashier);
        $register = CashRegister::query()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'name' => 'Caja Principal',
            'is_active' => true,
        ]);

        return [$company, $branch, $owner, $admin, $cashier, $secondCashier, $register];
    }

    private function servedOrder(Company $company, Branch $branch, User $user, string $total): Order
    {
        $order = Order::factory()->for($branch)->create([
            'company_id' => $company->id,
            'type' => OrderType::Takeaway,
            'subtotal' => $total,
            'total' => $total,
            'created_by' => $user->id,
        ]);
        OrderItem::factory()->for($order)->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'quantity' => '1.000',
            'unit_price' => $total,
            'line_total' => $total,
            'status' => OrderItemStatus::Served,
            'served_at' => now(),
            'created_by' => $user->id,
        ]);

        return $order;
    }

    private function member(Company $company, MembershipRole $role): User
    {
        $user = User::factory()->create();
        Membership::factory()->for($company)->for($user)->create(['role' => $role]);

        return $user;
    }

    private function actingInContext(User $user, Company $company, Branch $branch): static
    {
        return $this->actingAs($user)->withSession([
            'active_company_id' => $company->id,
            'active_branch_id' => $branch->id,
        ]);
    }
}
