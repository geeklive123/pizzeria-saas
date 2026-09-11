<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OrderHistoryFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'preset' => ['nullable', Rule::in(['today', 'yesterday', 'week', 'month', 'custom'])],
            'date_from' => [Rule::requiredIf($this->input('preset') === 'custom'), 'nullable', 'date'],
            'date_to' => [Rule::requiredIf($this->input('preset') === 'custom'), 'nullable', 'date', 'after_or_equal:date_from'],
        ];
    }
}
