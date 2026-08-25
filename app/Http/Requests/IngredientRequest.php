<?php

namespace App\Http\Requests;

use App\Models\Ingredient;
use App\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IngredientRequest extends FormRequest
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
        $company = app(CompanyContext::class)->company();
        $ingredientId = Ingredient::query()->forCompany($company)->where('ulid', $this->route('ingredient'))->value('id');

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('ingredients')->where('company_id', $company->getKey())->ignore($ingredientId)],
            'unit_id' => ['required', 'integer', Rule::exists('units', 'id')->where('company_id', $company->getKey())],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
