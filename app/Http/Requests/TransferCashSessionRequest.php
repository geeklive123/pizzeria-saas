<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TransferCashSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'destination_session' => ['required', 'string', 'max:26'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
