<?php

namespace App\Http\Requests;

use App\Enums\ProductType;
use App\Models\Product;
use App\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $variants = collect($this->input('variants', []))->map(function ($variant): array {
            $variant['is_active'] = isset($variant['is_active']);
            $variant['requires_preparation'] = isset($variant['requires_preparation']);

            return $variant;
        })->all();

        $this->merge(['is_active' => $this->boolean('is_active'), 'variants' => $variants]);
    }

    public function rules(): array
    {
        $company = app(CompanyContext::class)->company();
        $productId = Product::query()->forCompany($company)->where('ulid', $this->route('product'))->value('id');

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('products')->where('company_id', $company->getKey())->ignore($productId)],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('company_id', $company->getKey())],
            'type' => ['required', Rule::enum(ProductType::class)],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['required', 'boolean'],
            'variants' => ['required', 'array', 'min:1'],
            'variants.*.ulid' => ['nullable', 'string'],
            'variants.*.name' => ['required', 'string', 'max:255', 'distinct'],
            'variants.*.sku' => ['nullable', 'string', 'max:255'],
            'variants.*.price' => ['required', 'decimal:0,2', 'gte:0'],
            'variants.*.requires_preparation' => ['required', 'boolean'],
            'variants.*.is_active' => ['required', 'boolean'],
            'variants.*.sort_order' => ['required', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Escribe el nombre del producto o sabor.',
            'name.unique' => 'Ya existe un producto con ese nombre.',
            'type.required' => 'Selecciona el tipo de producto.',
            'type.enum' => 'El tipo de producto seleccionado no es válido.',
            'variants.required' => 'Agrega al menos un tamaño o presentación.',
            'variants.min' => 'Agrega al menos un tamaño o presentación.',
            'variants.*.name.required' => 'Escribe el nombre del tamaño o presentación.',
            'variants.*.name.distinct' => 'Ese tamaño o presentación ya está agregado. Usa nombres diferentes.',
            'variants.*.price.required' => 'Indica el precio del tamaño o presentación.',
            'variants.*.price.decimal' => 'El precio debe tener hasta dos decimales.',
            'variants.*.price.gte' => 'El precio no puede ser negativo.',
            'variants.*.sort_order.required' => 'Indica el orden del tamaño o presentación.',
            'variants.*.sort_order.integer' => 'El orden debe ser un número entero.',
        ];
    }
}
