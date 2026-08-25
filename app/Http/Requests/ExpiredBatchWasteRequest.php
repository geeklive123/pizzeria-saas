<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExpiredBatchWasteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'quantity' => ['required', 'decimal:0,3', 'gt:0'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
