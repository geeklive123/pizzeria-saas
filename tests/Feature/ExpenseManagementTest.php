<?php

namespace Tests\Feature;

use App\Actions\OpenCashSessionAction;
use App\Actions\RegisterExpenseAction;
use App\Actions\ReverseExpenseAction;
use App\Actions\SaveExpenseCategoryAction;
use App\Actions\SaveSupplierAction;
use App\Enums\CashMovementType;
use App\Enums\ExpenseDocumentType;
use App\Enums\ExpensePaymentMethod;
use App\Enums\ExpenseStatus;
use App\Enums\MembershipRole;
use App\Enums\Permission;
use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\InventoryMovement;
use App\Models\Membership;
use App\Models\Supplier;
use App\Models\User;
use App\Services\CashSessionSummaryService;
use Database\Seeders\ExpenseCategorySeeder;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpenseManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_expense_category_seeder_is_idempotent_and_categories_are_company_scoped(): void
    {
        [$company, $branch, $owner] = $this->context();
        $otherCompany = Company::factory()->create();
        $this->seed(ExpenseCategorySeeder::class);
        $this->seed(ExpenseCategorySeeder::class);

        $this->assertSame(9, ExpenseCategory::query()->forCompany($company)->count());
        $this->assertSame(9, ExpenseCategory::query()->forCompany($otherCompany)->count());
        $created = app(SaveExpenseCategoryAction::class)->execute($company, $owner, [
            'name' => 'Seguridad', 'description' => 'Alarmas', 'is_active' => true,
        ]);
        $this->assertSame($company->id, $created->company_id);
        $this->actingInContext($owner, $company, $branch)->get(route('expense-categories.edit', ExpenseCategory::query()->forCompany($otherCompany)->first()->ulid))->assertNotFound();
    }

    public function test_supplier_can_be_created_and_is_optional_but_cross_company_supplier_is_rejected(): void
    {
        [$company, $branch, $owner, $register, $category] = $this->context();
        $supplier = app(SaveSupplierAction::class)->execute($company, $owner, [
            'name' => 'Servicios SRL', 'tax_id' => '123', 'is_active' => true,
        ]);
        $expense = $this->register($company, $branch, $owner, $category, ExpensePaymentMethod::Qr);
        $this->assertNull($expense->supplier_id);
        $this->assertSame($company->id, $supplier->company_id);

        $otherCompany = Company::factory()->create();
        $otherSupplier = Supplier::factory()->for($otherCompany)->create();
        try {
            $this->register($company, $branch, $owner, $category, ExpensePaymentMethod::Qr, supplierId: $otherSupplier->id);
            $this->fail('A supplier from another company must be rejected.');
        } catch (ModelNotFoundException) {
            $this->assertDatabaseCount('expenses', 1);
        }
    }

    public function test_invoice_number_and_positive_amount_are_validated_by_the_expense_form(): void
    {
        [$company, $branch, $owner, $register, $category] = $this->context();
        $base = [
            'expense_date' => today()->toDateString(),
            'expense_category_id' => $category->id,
            'description' => 'Internet',
            'amount' => '100.00',
            'payment_method' => ExpensePaymentMethod::Qr->value,
            'document_type' => ExpenseDocumentType::WithInvoice->value,
        ];

        $this->actingInContext($owner, $company, $branch)->post(route('expenses.store'), $base)->assertSessionHasErrors('document_number');
        $this->actingInContext($owner, $company, $branch)->post(route('expenses.store'), array_merge($base, [
            'document_type' => ExpenseDocumentType::WithoutInvoice->value,
            'amount' => '0.00',
        ]))->assertSessionHasErrors('amount');
        $this->actingInContext($owner, $company, $branch)->post(route('expenses.store'), array_merge($base, [
            'document_type' => ExpenseDocumentType::WithoutInvoice->value,
        ]))->assertRedirect(route('expenses.index'));
        $this->assertDatabaseHas('expenses', ['document_number' => null, 'amount' => 100]);
    }

    public function test_cash_expense_requires_open_cash_creates_one_outflow_and_reduces_expected_cash_without_becoming_a_sale(): void
    {
        [$company, $branch, $owner, $register, $category] = $this->context();
        try {
            $this->register($company, $branch, $owner, $category, ExpensePaymentMethod::Cash);
            $this->fail('Cash expenses require an open session.');
        } catch (DomainException) {
            $this->assertDatabaseCount('expenses', 0);
        }

        $session = app(OpenCashSessionAction::class)->execute($register, '100.00', $owner);
        $inventoryMovementsBefore = InventoryMovement::query()->count();
        $expense = $this->register($company, $branch, $owner, $category, ExpensePaymentMethod::Cash, $session, amount: '30.00');
        $summary = app(CashSessionSummaryService::class)->calculate($session);

        $this->assertSame($session->id, $expense->cash_session_id);
        $this->assertDatabaseHas('cash_movements', ['reference_type' => Expense::class, 'reference_id' => $expense->id, 'type' => CashMovementType::ExpenseOut->value, 'amount' => 30]);
        $this->assertDatabaseCount('cash_movements', 2);
        $this->assertSame('70.00', $summary['expected_cash']);
        $this->assertSame('30.00', $summary['expense_cash']);
        $this->assertSame('0.00', $summary['sales_total']);
        $this->assertSame('0.00', $summary['manual_out']);
        $this->assertSame($inventoryMovementsBefore, InventoryMovement::query()->count());
    }

    public function test_qr_transfer_and_other_expenses_do_not_create_physical_cash_movements(): void
    {
        [$company, $branch, $owner, $register, $category] = $this->context();
        foreach ([ExpensePaymentMethod::Qr, ExpensePaymentMethod::Transfer, ExpensePaymentMethod::Other] as $method) {
            $expense = $this->register($company, $branch, $owner, $category, $method);
            $this->assertNull($expense->cash_session_id);
        }

        $this->assertDatabaseCount('expenses', 3);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_cash_expense_reversal_is_audited_compensates_cash_and_cannot_repeat(): void
    {
        [$company, $branch, $owner, $register, $category] = $this->context();
        $session = app(OpenCashSessionAction::class)->execute($register, '100.00', $owner);
        $expense = $this->register($company, $branch, $owner, $category, ExpensePaymentMethod::Cash, $session, amount: '40.00');
        $reversal = app(ReverseExpenseAction::class)->execute($expense, 'Documento duplicado', $owner, $session);

        $this->assertSame(ExpenseStatus::Reversed, $expense->refresh()->status);
        $this->assertSame($expense->id, $reversal->reversal_of_id);
        $this->assertDatabaseHas('cash_movements', ['reference_id' => $reversal->id, 'type' => CashMovementType::ExpenseReversal->value, 'amount' => 40]);
        $this->assertSame('100.00', app(CashSessionSummaryService::class)->calculate($session)['expected_cash']);

        $this->expectException(DomainException::class);
        app(ReverseExpenseAction::class)->execute($expense->refresh(), 'Otra vez', $owner, $session);
    }

    public function test_expense_category_and_supplier_history_are_not_deleted(): void
    {
        [$company, $branch, $owner, $register, $category] = $this->context();
        $supplier = Supplier::factory()->for($company)->create();
        $expense = $this->register($company, $branch, $owner, $category, ExpensePaymentMethod::Qr, supplierId: $supplier->id);

        foreach ([$expense, $category, $supplier] as $historical) {
            try {
                $historical->delete();
                $this->fail('Historical financial records and their references cannot be deleted.');
            } catch (DomainException) {
                $this->assertTrue($historical->fresh()->exists);
            }
        }

        $this->expectException(DomainException::class);
        $expense->update(['amount' => '999.00']);
    }

    public function test_roles_apply_expense_supplier_and_category_permissions(): void
    {
        [$company, $branch, $owner] = $this->context();
        $admin = $this->member($company, MembershipRole::Admin);
        $cashier = $this->member($company, MembershipRole::Cashier);
        $waiter = $this->member($company, MembershipRole::Waiter);
        $kitchen = $this->member($company, MembershipRole::Kitchen);

        $this->assertTrue(MembershipRole::Owner->allows(Permission::ManageSuppliers));
        $this->actingInContext($owner, $company, $branch)->get(route('expenses.index'))->assertOk();
        $this->actingInContext($admin, $company, $branch)->get(route('suppliers.index'))->assertOk();
        $this->actingInContext($admin, $company, $branch)->get(route('expense-categories.index'))->assertOk();
        $this->actingInContext($cashier, $company, $branch)->get(route('expenses.create'))->assertOk();
        $this->actingInContext($cashier, $company, $branch)->get(route('suppliers.index'))->assertForbidden();
        $this->actingInContext($waiter, $company, $branch)->get(route('expenses.index'))->assertForbidden();
        $this->actingInContext($kitchen, $company, $branch)->get(route('expenses.index'))->assertForbidden();
    }

    public function test_branch_and_category_context_failures_roll_back_cash_expense_atomically(): void
    {
        [$company, $branch, $owner, $register, $category] = $this->context();
        $session = app(OpenCashSessionAction::class)->execute($register, '50.00', $owner);
        $otherCompany = Company::factory()->create();
        $otherCategory = ExpenseCategory::factory()->for($otherCompany)->create();

        try {
            $this->register($company, $branch, $owner, $otherCategory, ExpensePaymentMethod::Cash, $session);
            $this->fail('A category from another company must be rejected.');
        } catch (ModelNotFoundException) {
            $this->assertDatabaseCount('expenses', 0);
            $this->assertDatabaseCount('cash_movements', 1);
            $this->assertSame('50.00', app(CashSessionSummaryService::class)->calculate($session)['expected_cash']);
        }
    }

    public function test_sequential_cash_expenses_cannot_overdraw_expected_physical_cash(): void
    {
        [$company, $branch, $owner, $register, $category] = $this->context();
        $session = app(OpenCashSessionAction::class)->execute($register, '100.00', $owner);
        $this->register($company, $branch, $owner, $category, ExpensePaymentMethod::Cash, $session, amount: '40.00');
        $this->register($company, $branch, $owner, $category, ExpensePaymentMethod::Cash, $session, amount: '60.00');

        try {
            $this->register($company, $branch, $owner, $category, ExpensePaymentMethod::Cash, $session, amount: '0.01');
            $this->fail('Concurrent-safe cash expenses cannot overdraw expected physical cash.');
        } catch (DomainException) {
            $this->assertDatabaseCount('expenses', 2);
            $this->assertDatabaseCount('cash_movements', 3);
            $this->assertSame('0.00', app(CashSessionSummaryService::class)->calculate($session)['expected_cash']);
        }
    }

    public function test_expense_money_code_does_not_use_binary_floating_point(): void
    {
        $files = [
            app_path('Actions/RegisterExpenseAction.php'),
            app_path('Actions/ReverseExpenseAction.php'),
            app_path('Services/CashSessionSummaryService.php'),
            app_path('Models/Expense.php'),
        ];
        foreach ($files as $file) {
            $this->assertDoesNotMatchRegularExpression('/floatval|doubleval|parseFloat|\(float\)|:\s*float\b/', file_get_contents($file));
        }
    }

    private function context(): array
    {
        $company = Company::factory()->create();
        $branch = Branch::factory()->for($company)->create();
        $owner = $this->member($company, MembershipRole::Owner);
        $register = CashRegister::query()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'name' => 'Caja Principal', 'is_active' => true]);
        $category = ExpenseCategory::factory()->for($company)->create(['name' => 'Servicios básicos']);

        return [$company, $branch, $owner, $register, $category];
    }

    private function member(Company $company, MembershipRole $role): User
    {
        $user = User::factory()->create();
        Membership::factory()->for($company)->for($user)->create(['role' => $role]);

        return $user;
    }

    private function register(Company $company, Branch $branch, User $user, ExpenseCategory $category, ExpensePaymentMethod $method, ?CashSession $session = null, ?int $supplierId = null, string $amount = '25.00'): Expense
    {
        return app(RegisterExpenseAction::class)->execute($company, $branch, $user, [
            'expense_category_id' => $category->id,
            'supplier_id' => $supplierId,
            'description' => 'Servicio operativo',
            'amount' => $amount,
            'expense_date' => today()->toDateString(),
            'document_type' => ExpenseDocumentType::WithoutInvoice->value,
            'document_number' => null,
            'payment_method' => $method->value,
            'notes' => null,
        ], $session);
    }

    private function actingInContext(User $user, Company $company, Branch $branch): static
    {
        return $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id]);
    }
}
