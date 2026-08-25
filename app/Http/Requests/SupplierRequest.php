<?php

namespace App\Http\Requests;

use App\Models\Supplier;
use App\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SupplierRequest extends FormRequest
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
        $supplierId = Supplier::query()->forCompany($companyId)->where('ulid', $this->route('supplier'))->value('id');

        return [
            'name' => ['required', 'string', 'max:190', Rule::unique('suppliers')->where('company_id', $companyId)->ignore($supplierId)],
            'tax_id' => ['nullable', 'string', 'max:60'],
            'phone' => ['nullable', 'string', 'max:60'],
            'email' => ['nullable', 'email', 'max:190'],
            'address' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
