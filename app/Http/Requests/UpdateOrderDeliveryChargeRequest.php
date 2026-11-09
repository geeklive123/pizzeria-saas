<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOrderDeliveryChargeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['enabled' => ['required', 'boolean'], 'amount' => ['nullable', 'required_if:enabled,1', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999999999.99']];
    }
}
