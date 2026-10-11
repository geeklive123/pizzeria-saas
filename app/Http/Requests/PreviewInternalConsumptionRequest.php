<?php

namespace App\Http\Requests;

use App\Enums\InternalConsumptionReason;
use App\Enums\OrderType;
use App\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PreviewInternalConsumptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $companyId = app(CompanyContext::class)->companyId();

        return [
            'reason' => ['required', Rule::enum(InternalConsumptionReason::class)],
            'notes' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'max:80'],
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
            'items.*.fulfillment_type' => ['required', Rule::enum(OrderType::class)],
            'items.*.notes' => ['nullable', 'string', 'max:500'],
            'items.*.sections' => ['required', 'array', 'min:1', 'max:4'],
            'items.*.sections.*.variant' => ['required', 'string', Rule::exists('product_variants', 'ulid')->where('company_id', $companyId)],
            'items.*.modifiers' => ['nullable', 'array'],
            'items.*.modifiers.*.option' => ['nullable', 'string', Rule::exists('modifier_options', 'ulid')->where('company_id', $companyId)],
            'items.*.modifiers.*.section_position' => ['nullable', 'integer', 'min:1', 'max:4'],
            'items.*.toppings' => ['nullable', 'array'],
            'items.*.toppings.*' => ['required', 'string', Rule::exists('modifier_options', 'ulid')->where('company_id', $companyId)],
        ];
    }
}
