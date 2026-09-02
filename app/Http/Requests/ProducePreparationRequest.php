<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProducePreparationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'lots' => ['required', 'integer', 'min:1', 'max:1000000'],
            'actual_yield' => ['nullable', 'decimal:0,3', 'gt:0'],
        ];
    }
}
