<?php

namespace App\Http\Requests;

use App\Enums\UnitType;
use App\Models\Unit;
use App\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_active' => $this->boolean('is_active')]);
    }

    public function rules(): array
    {
        $companyId = app(CompanyContext::class)->companyId();
        $unitId = Unit::query()->forCompany($companyId)->whereKey($this->route('unit'))->value('id');

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('units')->where('company_id', $companyId)->ignore($unitId)],
            'symbol' => ['required', 'string', 'max:20', Rule::unique('units')->where('company_id', $companyId)->ignore($unitId)],
            'type' => ['required', Rule::enum(UnitType::class)],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
