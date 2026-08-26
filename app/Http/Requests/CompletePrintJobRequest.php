<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CompletePrintJobRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->has('print_agent');
    }

    public function rules(): array
    {
        return ['claim_token' => ['required', 'string', 'size:64']];
    }
}
