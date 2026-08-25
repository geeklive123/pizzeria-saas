<?php

namespace App\Http\Requests;

use App\Enums\InventoryMovementType;
use App\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InventoryOperationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['no_expiration' => $this->boolean('no_expiration')]);

        if ($this->boolean('no_expiration')) {
            $this->merge(['expires_at' => null]);
        }
    }

    public function rules(): array
    {
        $companyId = app(CompanyContext::class)->companyId();

        return [
            'quantity' => ['required', 'decimal:0,3', 'gt:0'],
            'unit_id' => ['required', 'integer', Rule::exists('units', 'id')->where('company_id', $companyId)],
            'unit_cost' => ['nullable', 'required_if:operation,opening', 'decimal:0,6', 'gte:0'],
            'direction' => ['nullable', Rule::in([InventoryMovementType::AdjustmentIn->value, InventoryMovementType::AdjustmentOut->value])],
            'reason' => ['nullable', 'required_unless:operation,opening', 'string', 'max:500'],
            'operation' => ['required', Rule::in(['opening', 'adjustment', 'waste'])],
            'received_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
            'no_expiration' => ['required', 'boolean'],
        ];
    }
}
