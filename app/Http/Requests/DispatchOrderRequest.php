<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DispatchOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['discount_percentage' => ['nullable', 'decimal:0,2', 'gt:0', 'lte:100']];
    }
}
