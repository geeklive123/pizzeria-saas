<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CancelInternalConsumptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'confirm_not_prepared' => ['required', 'accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'confirm_not_prepared.required' => 'Debes confirmar que la pizza no fue preparada y que sus ingredientes no fueron utilizados.',
            'confirm_not_prepared.accepted' => 'Debes confirmar que la pizza no fue preparada y que sus ingredientes no fueron utilizados.',
        ];
    }
}
