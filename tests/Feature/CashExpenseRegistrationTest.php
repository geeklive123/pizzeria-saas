<?php

namespace Tests\Feature;

use App\Actions\CloseCashSessionAction;
use App\Actions\OpenCashSessionAction;
use App\Actions\ResolveOperationalExpenseCategoryAction;
use App\Enums\CashMovementType;
use App\Enums\ExpenseDocumentType;
use App\Enums\ExpensePaymentMethod;
use App\Enums\MembershipRole;
use App\Enums\Permission;
use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\Company;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\InventoryMovement;
use App\Models\Membership;
use App\Models\Order;
use App\Models\User;
use App\Services\CashSessionSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashExpenseRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_cashier_registers_an_audited_cash_expense_from_cash_without_changing_sales_or_inventory(): void
    {
        [$company, $branch, $cashier, $register] = $this->context();
        $session = app(OpenCashSessionAction::class)->execute($register, '100.00', $cashier);
        $ordersBefore = Order::query()->count();
        $inventoryMovementsBefore = InventoryMovement::query()->count();

        $this->actingInContext($cashier, $company, $branch)
            ->post(route('cash.expenses.store'), $this->expenseData([
                'description' => 'Compra de material de limpieza',
                'amount' => '30.00',
                'payment_method' => ExpensePaymentMethod::Cash->value,
            ]))
            ->assertRedirect(route('cash.current'))
            ->assertSessionHas('success', 'Egreso registrado correctamente.');

        $expense = Expense::query()->sole();
        $this->assertSame($company->id, $expense->company_id);
        $this->assertSame($branch->id, $expense->branch_id);
        $this->assertSame($cashier->id, $expense->created_by);
        $this->assertSame(ResolveOperationalExpenseCategoryAction::NAME, $expense->category->name);
        $this->assertNull($expense->supplier_id);
        $this->assertSame($session->id, $expense->cash_session_id);
        $this->assertSame('30.00', $expense->amount);
        $this->assertSame('Compra de material de limpieza', $expense->description);
        $this->assertSame(today()->toDateString(), $expense->expense_date->toDateString());
        $this->assertSame(ExpensePaymentMethod::Cash, $expense->payment_method);
        $this->assertSame(ExpenseDocumentType::WithoutInvoice, $expense->document_type);
        $this->assertNull($expense->document_number);
        $this->assertNull($expense->notes);
        $this->assertDatabaseHas('cash_movements', [
            'cash_session_id' => $session->id,
            'reference_type' => Expense::class,
            'reference_id' => $expense->id,
            'type' => CashMovementType::ExpenseOut->value,
            'amount' => 30,
            'created_by' => $cashier->id,
        ]);
        $this->assertSame('30.00', app(CashSessionSummaryService::class)->calculate($session)['expense_cash']);
        $this->assertSame('70.00', app(CashSessionSummaryService::class)->calculate($session)['expected_cash']);
        $this->assertSame($ordersBefore, Order::query()->count());
        $this->assertSame($inventoryMovementsBefore, InventoryMovement::query()->count());

        $this->actingInContext($cashier, $company, $branch)->get(route('cash.index'))
            ->assertOk()
            ->assertSee('Registrar egreso')
            ->assertSee('value=\'cash\'', false)
            ->assertSee('value=\'qr\'', false)
            ->assertSee('value=\'transfer\'', false)
            ->assertSee('value=\'other\'', false)
            ->assertDontSee('Categoría de gasto')
            ->assertDontSee('Proveedor (opcional)')
            ->assertDontSee('Tipo de documento')
            ->assertDontSee('Número de documento')
            ->assertDontSee('Notas (opcional)')
            ->assertSee('Gastos en efectivo')
            ->assertSee('70,00');
    }

    public function test_cashier_registers_qr_without_expense_out_or_physical_cash_change(): void
    {
        [$company, $branch, $cashier, $register] = $this->context();
        $session = app(OpenCashSessionAction::class)->execute($register, '100.00', $cashier);
        $cashMovementsBefore = $session->movements()->count();
        $ordersBefore = Order::query()->count();
        $inventoryMovementsBefore = InventoryMovement::query()->count();

        $this->actingInContext($cashier, $company, $branch)
            ->post(route('cash.expenses.store'), $this->expenseData([
                'amount' => '25.00',
                'payment_method' => ExpensePaymentMethod::Qr->value,
            ]))
            ->assertRedirect(route('cash.current'))
            ->assertSessionHas('success', 'Egreso registrado correctamente.');

        $expense = Expense::query()->sole();
        $this->assertNull($expense->cash_session_id);
        $this->assertSame(ExpensePaymentMethod::Qr, $expense->payment_method);
        $this->assertSame($cashMovementsBefore, $session->movements()->count());
        $this->assertDatabaseMissing('cash_movements', [
            'reference_type' => Expense::class,
            'reference_id' => $expense->id,
            'type' => CashMovementType::ExpenseOut->value,
        ]);
        $this->assertSame('0.00', app(CashSessionSummaryService::class)->calculate($session)['expense_cash']);
        $this->assertSame('100.00', app(CashSessionSummaryService::class)->calculate($session)['expected_cash']);
        $this->assertSame($ordersBefore, Order::query()->count());
        $this->assertSame($inventoryMovementsBefore, InventoryMovement::query()->count());
    }

    public function test_cash_expense_cannot_exceed_expected_cash_and_requires_an_open_session(): void
    {
        [$company, $branch, $cashier, $register] = $this->context();
        $session = app(OpenCashSessionAction::class)->execute($register, '20.00', $cashier);

        $this->actingInContext($cashier, $company, $branch)
            ->from(route('cash.index'))
            ->post(route('cash.expenses.store'), $this->expenseData([
                'amount' => '20.01',
                'payment_method' => ExpensePaymentMethod::Cash->value,
            ]))
            ->assertRedirect(route('cash.index'))
            ->assertSessionHasErrors('expense');
        $this->assertDatabaseCount('expenses', 0);
        $this->assertSame('20.00', app(CashSessionSummaryService::class)->calculate($session)['expected_cash']);

        app(CloseCashSessionAction::class)->execute($session, '20.00', $cashier);
        $this->actingInContext($cashier, $company, $branch)
            ->from(route('cash.index'))
            ->post(route('cash.expenses.store'), $this->expenseData([
                'amount' => '10.00',
                'payment_method' => ExpensePaymentMethod::Cash->value,
            ]))
            ->assertRedirect(route('cash.index'))
            ->assertSessionHasErrors('expense');
        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_cashier_permissions_allow_only_viewing_and_creating_expenses(): void
    {
        [$company, $branch, $cashier, $register, $category] = $this->context();

        $this->assertTrue(MembershipRole::Cashier->allows(Permission::ViewExpenses));
        $this->assertTrue(MembershipRole::Cashier->allows(Permission::CreateExpenses));
        $this->assertFalse(MembershipRole::Cashier->allows(Permission::ReverseExpenses));
        $this->assertFalse(MembershipRole::Cashier->allows(Permission::RegisterManualCashMovements));

        $this->actingInContext($cashier, $company, $branch)->get(route('cash.index'))
            ->assertOk()
            ->assertDontSee('Registrar egreso');

        app(OpenCashSessionAction::class)->execute($register, '100.00', $cashier);
        $this->actingInContext($cashier, $company, $branch)->get(route('cash.index'))
            ->assertOk()
            ->assertSee('Registrar egreso')
            ->assertDontSee('Registrar movimiento manual');

        $expense = Expense::factory()->for($company)->for($branch)->for($category, 'category')->create([
            'created_by' => $cashier->id,
            'payment_method' => ExpensePaymentMethod::Qr,
        ]);
        $this->actingInContext($cashier, $company, $branch)
            ->post(route('expenses.reverse', $expense->ulid), ['reason' => 'No autorizado'])
            ->assertForbidden();
        $this->assertDatabaseMissing('expenses', ['reversal_of_id' => $expense->id]);
    }

    public function test_owner_and_admin_can_still_register_expenses_from_cash(): void
    {
        [$company, $branch] = $this->context();

        foreach ([MembershipRole::Owner, MembershipRole::Admin] as $role) {
            $user = $this->member($company, $role);
            $register = CashRegister::query()->create([
                'company_id' => $company->id,
                'branch_id' => $branch->id,
                'name' => $role->label(),
                'is_active' => true,
            ]);
            app(OpenCashSessionAction::class)->execute($register, '50.00', $user);

            $this->actingInContext($user, $company, $branch)
                ->post(route('cash.expenses.store'), $this->expenseData([
                    'description' => 'Egreso '.$role->value,
                    'payment_method' => ExpensePaymentMethod::Qr->value,
                ]))
                ->assertRedirect(route('cash.current'));
        }

        $this->assertDatabaseCount('expenses', 2);
    }

    public function test_default_operational_category_is_created_once_and_reused(): void
    {
        [$company, $branch, $cashier, $register] = $this->context();
        app(OpenCashSessionAction::class)->execute($register, '100.00', $cashier);

        foreach (['Primero', 'Segundo'] as $description) {
            $this->actingInContext($cashier, $company, $branch)
                ->post(route('cash.expenses.store'), $this->expenseData([
                    'description' => $description,
                    'payment_method' => ExpensePaymentMethod::Qr->value,
                ]))
                ->assertRedirect(route('cash.current'));
        }

        $category = ExpenseCategory::query()->forCompany($company)
            ->where('name', ResolveOperationalExpenseCategoryAction::NAME)->sole();
        $this->assertSame(2, Expense::query()->where('expense_category_id', $category->id)->count());
    }

    private function context(): array
    {
        $company = Company::factory()->create();
        $branch = Branch::factory()->for($company)->create();
        $cashier = $this->member($company, MembershipRole::Cashier);
        $register = CashRegister::query()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'name' => 'Caja principal',
            'is_active' => true,
        ]);
        $category = ExpenseCategory::factory()->for($company)->create(['name' => 'Servicios']);

        return [$company, $branch, $cashier, $register, $category];
    }

    private function member(Company $company, MembershipRole $role): User
    {
        $user = User::factory()->create();
        Membership::factory()->for($company)->for($user)->create(['role' => $role]);

        return $user;
    }

    private function expenseData(array $overrides = []): array
    {
        return array_merge([
            'description' => 'Gasto operativo',
            'amount' => '10.00',
            'payment_method' => ExpensePaymentMethod::Cash->value,
        ], $overrides);
    }

    private function actingInContext(User $user, Company $company, Branch $branch): static
    {
        return $this->actingAs($user)->withSession([
            'active_company_id' => $company->id,
            'active_branch_id' => $branch->id,
        ]);
    }
}
