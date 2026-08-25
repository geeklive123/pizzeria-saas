<?php

namespace App\Http\Requests;

use App\Models\ExpenseCategory;
use App\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExpenseCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_active' => $this->boolean('is_active')]);
    }

    public function rules(): array
    {
        $companyId = app(CompanyContext::class)->companyId();
        $categoryId = ExpenseCategory::query()->forCompany($companyId)->where('ulid', $this->route('expense_category'))->value('id');

        return [
            'name' => ['required', 'string', 'max:120', Rule::unique('expense_categories')->where('company_id', $companyId)->ignore($categoryId)],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
