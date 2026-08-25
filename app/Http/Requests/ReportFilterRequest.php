<?php

namespace App\Http\Requests;

use App\Enums\ExpenseDocumentType;
use App\Enums\ExpensePaymentMethod;
use App\Enums\ExpenseStatus;
use App\Enums\PaymentMethod;
use App\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReportFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $companyId = app(CompanyContext::class)->companyId();

        return [
            'preset' => ['nullable', Rule::in(['today', 'yesterday', 'week', 'month', 'previous_month', 'custom'])],
            'date_from' => [Rule::requiredIf($this->input('preset') === 'custom'), 'nullable', 'date'],
            'date_to' => [Rule::requiredIf($this->input('preset') === 'custom'), 'nullable', 'date', 'after_or_equal:date_from'],
            'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'expense_method' => ['nullable', Rule::enum(ExpensePaymentMethod::class)],
            'expense_status' => ['nullable', Rule::enum(ExpenseStatus::class)],
            'document_type' => ['nullable', Rule::enum(ExpenseDocumentType::class)],
            'expense_category_id' => ['nullable', 'integer', Rule::exists('expense_categories', 'id')->where('company_id', $companyId)],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('company_id', $companyId)],
            'product_id' => ['nullable', 'integer', Rule::exists('products', 'id')->where('company_id', $companyId)],
            'variant_id' => ['nullable', 'integer', Rule::exists('product_variants', 'id')->where('company_id', $companyId)],
            'sort' => ['nullable', Rule::in(['value', 'low_stock', 'expiration', 'name'])],
        ];
    }
}
