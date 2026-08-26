<?php

namespace App\Http\Requests;

class FailPrintJobRequest extends CompletePrintJobRequest
{
    public function rules(): array
    {
        return parent::rules() + ['error' => ['required', 'string', 'max:500']];
    }
}
