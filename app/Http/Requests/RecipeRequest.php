<?php

namespace App\Http\Requests;

use App\Enums\RecipeComponentType;
use App\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecipeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $items = collect($this->input('items', []))->map(function (array $item): array {
            $item['component_type'] ??= RecipeComponentType::Topping->value;

            return $item;
        })->all();
        $this->merge(['is_active' => $this->boolean('is_active'), 'items' => $items]);
    }

    public function rules(): array
    {
        $companyId = app(CompanyContext::class)->companyId();

        return [
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['required', 'boolean'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.ingredient_id' => ['required', 'integer', 'distinct', Rule::exists('ingredients', 'id')->where('company_id', $companyId)],
            'items.*.component_type' => ['required', Rule::enum(RecipeComponentType::class)],
            'items.*.quantity' => ['required', 'decimal:0,3', 'gt:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Escribe un nombre para la receta.',
            'items.required' => 'Agrega al menos un ingrediente.',
            'items.min' => 'Agrega al menos un ingrediente.',
            'items.*.ingredient_id.required' => 'Selecciona un ingrediente.',
            'items.*.ingredient_id.distinct' => 'Ese ingrediente ya está incluido en la receta.',
            'items.*.ingredient_id.exists' => 'El ingrediente seleccionado no pertenece a la empresa activa.',
            'items.*.quantity.required' => 'Indica la cantidad del ingrediente.',
            'items.*.quantity.decimal' => 'La cantidad debe tener hasta tres decimales.',
            'items.*.quantity.gt' => 'La cantidad debe ser mayor que cero.',
        ];
    }
}
