<?php

namespace Database\Factories;

use App\Enums\ExpenseDocumentType;
use App\Enums\ExpensePaymentMethod;
use App\Enums\ExpenseStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\ExpenseCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ExpenseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'branch_id' => Branch::factory(),
            'expense_category_id' => ExpenseCategory::factory(),
            'supplier_id' => null,
            'description' => fake()->sentence(4),
            'amount' => fake()->randomElement(['25.00', '80.50', '150.00']),
            'expense_date' => today(),
            'document_type' => ExpenseDocumentType::WithoutInvoice,
            'document_number' => null,
            'payment_method' => ExpensePaymentMethod::Qr,
            'cash_session_id' => null,
            'status' => ExpenseStatus::Posted,
            'notes' => null,
            'created_by' => User::factory(),
            'approved_by' => null,
            'reversal_of_id' => null,
        ];
    }
}
