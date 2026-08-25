<?php

namespace App\Http\Requests;

use App\Enums\OrderType;
use App\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddOrderItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'variant' => ['nullable', 'required_without:sections', 'string', Rule::exists('product_variants', 'ulid')->where('company_id', app(CompanyContext::class)->companyId())],
            'sections' => ['nullable', 'required_without:variant', 'array', 'min:1', 'max:4'],
            'sections.*.variant' => ['required', 'string', 'distinct', Rule::exists('product_variants', 'ulid')->where('company_id', app(CompanyContext::class)->companyId())],
            'modifiers' => ['nullable', 'array'],
            'modifiers.*.option' => ['nullable', 'string', Rule::exists('modifier_options', 'ulid')->where('company_id', app(CompanyContext::class)->companyId())],
            'modifiers.*.section_position' => ['nullable', 'integer', 'min:1', 'max:4'],
            'quantity' => ['required', 'decimal:0,3', 'gt:0'],
            'fulfillment_type' => ['nullable', Rule::enum(OrderType::class)],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'sections.required_without' => 'Selecciona al menos un sabor para la pizza.',
            'sections.min' => 'Selecciona al menos un sabor para la pizza.',
            'sections.max' => 'Una pizza puede tener como máximo cuatro sabores.',
            'sections.*.variant.required' => 'Selecciona un sabor.',
            'sections.*.variant.distinct' => 'Ese sabor ya está seleccionado. Elige otro sabor o utiliza la opción "1 sabor".',
            'sections.*.variant.exists' => 'El sabor seleccionado no está disponible.',
            'quantity.required' => 'Indica la cantidad.',
            'quantity.decimal' => 'La cantidad debe tener hasta tres decimales.',
            'quantity.gt' => 'La cantidad debe ser mayor que cero.',
        ];
    }
}
