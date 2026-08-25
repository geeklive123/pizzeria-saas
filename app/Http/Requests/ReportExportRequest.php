<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class ReportExportRequest extends ReportFilterRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['report' => $this->route('report')]);
    }

    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'report' => ['required', Rule::in(['sales', 'expenses', 'purchases', 'inventory', 'cash'])],
        ]);
    }
}
