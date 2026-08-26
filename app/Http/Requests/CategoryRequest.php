<?php

namespace App\Http\Requests;

use App\Models\Category;
use App\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CategoryRequest extends FormRequest
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
        $categoryId = Category::query()->forCompany($companyId)
            ->where('ulid', $this->route('category'))->value('id');

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('categories')->where('company_id', $companyId)->ignore($categoryId)],
            'description' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['required', 'integer', 'min:0'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
