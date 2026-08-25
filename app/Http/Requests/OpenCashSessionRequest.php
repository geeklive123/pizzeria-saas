<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OpenCashSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'register' => ['required', 'string', 'max:26'],
            'opening_amount' => ['required', 'decimal:0,2', 'gte:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
