<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateKitchenDispatchDiscountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['discount_percentage' => ['required', 'decimal:0,2', 'gte:0', 'lte:100']];
    }
}
