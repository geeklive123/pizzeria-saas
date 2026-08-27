<?php

namespace App\Http\Requests;

use App\Services\ToppingSizeCatalogService;
use App\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ToppingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $rules = collect($this->input('size_rules', []))->map(function ($rule): array {
            $rule['price_delta'] = filled($rule['price_delta'] ?? null) ? $rule['price_delta'] : null;
            $rule['quantity'] = filled($rule['quantity'] ?? null) ? $rule['quantity'] : null;

            return $rule;
        })->all();

        $this->merge([
            'is_active' => $this->boolean('is_active'),
            'inventory_item_ulid' => filled($this->input('inventory_item_ulid')) ? $this->input('inventory_item_ulid') : null,
            'default_quantity' => filled($this->input('default_quantity')) ? $this->input('default_quantity') : null,
            'size_rules' => $rules,
        ]);
    }

    public function rules(): array
    {
        $company = app(CompanyContext::class)->company();
        $sizeKeys = app(ToppingSizeCatalogService::class)->forCompany($company)->pluck('key')->all();

        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'price_delta' => ['required', 'decimal:0,2', 'gte:0'],
            'inventory_item_ulid' => [
                'nullable',
                'string',
                Rule::exists('inventory_items', 'ulid')->where('company_id', $company->getKey()),
            ],
            'default_quantity' => ['nullable', 'decimal:0,3', 'gt:0'],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0'],
            'size_rules' => ['array'],
            'size_rules.*.size_key' => ['required', 'string', Rule::in($sizeKeys), 'distinct'],
            'size_rules.*.price_delta' => ['nullable', 'decimal:0,2', 'gte:0'],
            'size_rules.*.quantity' => ['nullable', 'decimal:0,3', 'gt:0'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $hasInventory = filled($this->input('inventory_item_ulid'));
            $hasDefaultQuantity = filled($this->input('default_quantity'));
            $hasSizeQuantity = collect($this->input('size_rules', []))->contains(
                fn ($rule) => filled($rule['quantity'] ?? null),
            );

            if ($hasInventory && ! $hasDefaultQuantity && ! $hasSizeQuantity) {
                $validator->errors()->add('inventory_item_ulid', 'Define una cantidad general o al menos una cantidad por tamaño.');
            }
            if (! $hasInventory && ($hasDefaultQuantity || $hasSizeQuantity)) {
                $validator->errors()->add('inventory_item_ulid', 'Selecciona un artículo de inventario para registrar cantidades de consumo.');
            }
        }];
    }
}
