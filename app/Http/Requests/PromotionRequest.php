<?php

namespace App\Http\Requests;

use App\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PromotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active' => $this->boolean('is_active'),
            'starts_at' => filled($this->input('starts_at')) ? $this->input('starts_at') : null,
            'ends_at' => filled($this->input('ends_at')) ? $this->input('ends_at') : null,
        ]);
    }

    public function rules(): array
    {
        $companyId = app(CompanyContext::class)->companyId();

        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'price' => ['required', 'decimal:0,2', 'gte:0'],
            'is_active' => ['required', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'components' => ['required', 'array', 'min:1'],
            'components.*.inventory_item_ulid' => [
                'required', 'string', 'distinct',
                Rule::exists('inventory_items', 'ulid')->where('company_id', $companyId)->where('is_active', true),
            ],
            'components.*.quantity' => ['required', 'decimal:0,3', 'gt:0'],
        ];
    }
}
