<?php

namespace App\Http\Requests;

use App\Enums\ExpensePaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CashExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'description' => ['required', 'string', 'max:500'],
            'amount' => ['required', 'decimal:0,2', 'gt:0'],
            'payment_method' => ['required', Rule::enum(ExpensePaymentMethod::class)],
        ];
    }
}
