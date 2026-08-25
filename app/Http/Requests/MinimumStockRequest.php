<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MinimumStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['minimum_quantity' => ['nullable', 'decimal:0,3', 'gte:0']];
    }
}
