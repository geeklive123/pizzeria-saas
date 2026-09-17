<?php

namespace App\Http\Requests;

use App\Enums\MembershipRole;
use App\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserAccessLogFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array(
            app(CompanyContext::class)->membership()->role,
            [MembershipRole::Owner, MembershipRole::Admin],
            true,
        );
    }

    public function rules(): array
    {
        $companyId = app(CompanyContext::class)->companyId();

        return [
            'preset' => ['nullable', Rule::in(['today', 'yesterday', 'week', 'custom'])],
            'date_from' => [Rule::requiredIf($this->input('preset') === 'custom'), 'nullable', 'date'],
            'date_to' => [Rule::requiredIf($this->input('preset') === 'custom'), 'nullable', 'date', 'after_or_equal:date_from'],
            'user_id' => ['nullable', 'integer', Rule::exists('user_access_logs', 'user_id')->where('company_id', $companyId)],
            'role' => ['nullable', Rule::in([MembershipRole::Cashier->value, MembershipRole::Kitchen->value])],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('company_id', $companyId)],
            'status' => ['nullable', Rule::in(['active', 'closed'])],
        ];
    }
}
