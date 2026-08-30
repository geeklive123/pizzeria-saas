<?php

namespace App\Http\Requests;

use App\Enums\TableChargeMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OpenTableOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'charge_mode' => ['required', Rule::enum(TableChargeMode::class)],
            'customer_name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
