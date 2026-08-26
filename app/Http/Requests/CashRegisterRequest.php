<?php

namespace App\Http\Requests;

use App\Models\CashRegister;
use App\Support\BranchContext;
use App\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CashRegisterRequest extends FormRequest
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
        $branchId = app(BranchContext::class)->branch()->getKey();
        $registerId = CashRegister::query()->forCompany($companyId)->forBranch($branchId)
            ->where('ulid', $this->route('cash_register'))->value('id');

        return [
            'name' => [
                'required', 'string', 'max:120',
                Rule::unique('cash_registers')->where(
                    fn ($query) => $query->where('company_id', $companyId)->where('branch_id', $branchId),
                )->ignore($registerId),
            ],
            'is_active' => ['required', 'boolean'],
            'onboarding' => ['nullable', 'boolean'],
        ];
    }
}
