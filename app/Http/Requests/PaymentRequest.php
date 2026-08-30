<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'method' => ['required', Rule::in([PaymentMethod::Cash->value, PaymentMethod::Qr->value])],
            'amount' => ['required', 'decimal:0,2', 'gt:0'],
            'received_amount' => ['nullable', 'decimal:0,2', 'gte:0'],
            'reference' => ['nullable', 'string', 'max:190'],
            'idempotency_key' => ['required', 'string', 'max:64'],
            'kitchen_dispatch' => ['nullable', 'ulid'],
        ];
    }

    public function messages(): array
    {
        return [
            'method.required' => 'Selecciona un método de pago.',
            'method.in' => 'El método de pago seleccionado no es válido.',
            'amount.required' => 'Indica el monto a cobrar.',
            'amount.decimal' => 'El monto debe tener hasta dos decimales.',
            'amount.gt' => 'El monto debe ser mayor que cero.',
            'received_amount.decimal' => 'El efectivo recibido debe tener hasta dos decimales.',
            'received_amount.gte' => 'El efectivo recibido no puede ser negativo.',
            'idempotency_key.required' => 'No se pudo identificar la operación de pago. Inténtalo nuevamente.',
        ];
    }
}
