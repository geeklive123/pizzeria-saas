<?php

namespace App\Http\Requests;

use App\Enums\OrderType;
use App\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddPromotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'promotion' => ['required', 'string', Rule::exists('promotions', 'ulid')->where('company_id', app(CompanyContext::class)->companyId())],
            'quantity' => ['required', 'decimal:0,3', 'gt:0'],
            'fulfillment_type' => ['nullable', Rule::enum(OrderType::class)],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
