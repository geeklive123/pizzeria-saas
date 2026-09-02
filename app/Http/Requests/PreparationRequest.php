<?php

namespace App\Http\Requests;

use App\Models\Preparation;
use App\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PreparationRequest extends FormRequest
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

        return [
            'name' => ['required', 'string', 'max:255'],
            'output_inventory_item_id' => ['required', 'integer', Rule::exists('inventory_items', 'id')
                ->where('company_id', $companyId)->whereNotNull('ingredient_id')->where('is_active', true),
                Rule::unique('preparations')->where('company_id', $companyId)->ignore($this->preparationId())],
            'theoretical_yield' => ['required', 'decimal:0,3', 'gt:0'],
            'is_active' => ['required', 'boolean'],
            'components' => ['required', 'array', 'min:1'],
            'components.*.inventory_item_id' => ['required', 'integer', 'distinct', Rule::exists('inventory_items', 'id')
                ->where('company_id', $companyId)->whereNotNull('ingredient_id')->where('is_active', true)],
            'components.*.quantity' => ['required', 'decimal:0,3', 'gt:0'],
        ];
    }

    private function preparationId(): ?int
    {
        return Preparation::query()->forCompany(app(CompanyContext::class)->companyId())
            ->where('ulid', $this->route('preparation'))->value('id');
    }
}
