<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CloseCashSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'counted_cash_amount' => ['required', 'decimal:0,2', 'gte:0'],
            'closing_observation' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
