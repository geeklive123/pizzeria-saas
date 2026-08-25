<?php

namespace App\Http\Requests;

use App\Enums\ExpenseDocumentType;
use App\Enums\ExpensePaymentMethod;
use App\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $companyId = app(CompanyContext::class)->companyId();

        return [
            'expense_date' => ['required', 'date', 'before_or_equal:today'],
            'expense_category_id' => ['required', 'integer', Rule::exists('expense_categories', 'id')->where('company_id', $companyId)->where('is_active', true)],
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')->where('company_id', $companyId)->where('is_active', true)],
            'description' => ['required', 'string', 'max:500'],
            'amount' => ['required', 'decimal:0,2', 'gt:0'],
            'document_type' => ['required', Rule::enum(ExpenseDocumentType::class)],
            'document_number' => [Rule::requiredIf($this->input('document_type') === ExpenseDocumentType::WithInvoice->value), 'nullable', 'string', 'max:190'],
            'payment_method' => ['required', Rule::enum(ExpensePaymentMethod::class)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
