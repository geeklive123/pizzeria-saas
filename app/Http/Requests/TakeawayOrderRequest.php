<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TakeawayOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['customer_name' => ['nullable', 'string', 'max:255'], 'customer_phone' => ['nullable', 'string', 'max:30'], 'notes' => ['nullable', 'string', 'max:1000']];
    }
}
