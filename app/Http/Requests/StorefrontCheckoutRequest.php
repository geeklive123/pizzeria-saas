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

    protected function prepareForValidation(): void
    {
        $normalized = [];
        foreach (['customer_name', 'customer_phone', 'address', 'reference', 'notes'] as $field) {
            $value = $this->input($field);
            if (is_string($value)) {
                $normalized[$field] = trim($value);
            }
        }
        if (isset($normalized['customer_name'])) {
            $normalized['customer_name'] = preg_replace('/\s+/u', ' ', $normalized['customer_name']);
        }
        if (isset($normalized['customer_phone']) && preg_match('/^\+?[0-9 ()-]+$/D', $normalized['customer_phone'])) {
            $normalized['customer_phone'] = preg_replace('/[ ()-]/', '', $normalized['customer_phone']);
        }
        $this->merge($normalized);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'customer_name' => ['required', 'string', 'max:120'],
            'customer_phone' => ['required', 'string', 'regex:/^\+?[0-9]{7,15}$/D'],
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
