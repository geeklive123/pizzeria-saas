<?php

namespace App\Http\Requests;

use App\Models\RestaurantTable;
use App\Support\BranchContext;
use App\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RestaurantTableRequest extends FormRequest
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
        $branchId = app(BranchContext::class)->branch()->id;
        $tableId = RestaurantTable::query()->where('company_id', $companyId)->where('branch_id', $branchId)
            ->where('ulid', $this->route('table'))->value('id');

        return [
            'name' => ['required', 'string', 'max:50', Rule::unique('restaurant_tables')->where(fn ($query) => $query->where('company_id', $companyId)->where('branch_id', $branchId))->ignore($tableId)],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'sort_order' => ['required', 'integer', 'min:0'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
