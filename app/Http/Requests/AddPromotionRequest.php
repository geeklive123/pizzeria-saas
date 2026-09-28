<?php

namespace App\Http\Requests;

use App\Enums\OrderType;
use App\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddPromotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'promotion' => ['required', 'string', Rule::exists('promotions', 'ulid')->where('company_id', app(CompanyContext::class)->companyId())],
            'quantity' => ['required', 'decimal:0,3', 'gt:0'],
            'fulfillment_type' => ['nullable', Rule::enum(OrderType::class)],
            'notes' => ['nullable', 'string', 'max:500'],
            'pizzas' => ['nullable', 'array', 'max:48'],
            'pizzas.*.group' => ['required', 'string', Rule::exists('promotion_selection_groups', 'ulid')->where('company_id', app(CompanyContext::class)->companyId())],
            'pizzas.*.sections' => ['required', 'array', 'min:1', 'max:4'],
            'pizzas.*.sections.*.variant' => ['required', 'string', Rule::exists('product_variants', 'ulid')->where('company_id', app(CompanyContext::class)->companyId())],
            'pizzas.*.modifiers' => ['nullable', 'array'],
            'pizzas.*.modifiers.*.option' => ['nullable', 'string', Rule::exists('modifier_options', 'ulid')->where('company_id', app(CompanyContext::class)->companyId())],
            'pizzas.*.modifiers.*.section_position' => ['nullable', 'integer', 'min:1', 'max:4'],
            'pizzas.*.toppings' => ['nullable', 'array'],
            'pizzas.*.toppings.*' => ['required', 'string', 'distinct', Rule::exists('modifier_options', 'ulid')->where('company_id', app(CompanyContext::class)->companyId())],
        ];
    }
}
