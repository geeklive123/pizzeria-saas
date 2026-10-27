<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorefrontCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'customer_name' => ['required', 'string', 'max:120'],
            'customer_phone' => ['required', 'string', 'max:30'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'fulfillment' => ['required', Rule::in(['delivery', 'pickup'])],
            'address' => ['nullable', 'required_if:fulfillment,delivery', 'string', 'max:500'],
            'reference' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:40'],
            'items.*.variant_ulid' => ['required', 'string', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:20'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'address.required_if' => 'La dirección es obligatoria para delivery.',
            'items.required' => 'Agrega al menos un producto antes de continuar.',
            'items.min' => 'Agrega al menos un producto antes de continuar.',
        ];
    }
}
