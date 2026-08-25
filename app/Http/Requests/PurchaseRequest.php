<?php

namespace App\Http\Requests;

use App\Models\Purchase;
use App\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $items = collect($this->input('items', []))->map(function (array $item): array {
            $item['no_expiration'] = isset($item['no_expiration']);

            if ($item['no_expiration']) {
                $item['expires_at'] = null;
            }

            return $item;
        })->all();

        $this->merge(['items' => $items]);
    }

    public function rules(): array
    {
        $companyId = app(CompanyContext::class)->companyId();

        return [
            'supplier_name' => ['nullable', 'string', 'max:255'],
            'document_number' => ['nullable', 'string', 'max:255', Rule::unique('purchases')->where('company_id', $companyId)->ignore($this->purchaseId())],
            'purchased_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.inventory_item_id' => ['required', 'integer', 'distinct', Rule::exists('inventory_items', 'id')->where('company_id', $companyId)],
            'items.*.quantity' => ['required', 'decimal:0,3', 'gt:0'],
            'items.*.input_unit_id' => ['required', 'integer', Rule::exists('units', 'id')->where('company_id', $companyId)],
            'items.*.unit_cost' => ['required', 'decimal:0,6', 'gte:0'],
            'items.*.expires_at' => ['nullable', 'date'],
            'items.*.no_expiration' => ['required', 'boolean'],
        ];
    }

    private function purchaseId(): ?int
    {
        return Purchase::query()
            ->where('company_id', app(CompanyContext::class)->companyId())
            ->where('ulid', $this->route('purchase'))
            ->value('id');
    }
}
