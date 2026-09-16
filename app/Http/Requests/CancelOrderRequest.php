<?php

namespace App\Http\Requests;

use App\Enums\MembershipRole;
use App\Enums\Permission;
use App\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;

class CancelOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $context = app(CompanyContext::class);

        if (! $user || ! $context->hasCompany()) {
            return false;
        }

        $membership = $user->membershipFor($context->company());

        return $membership !== null
            && in_array($membership->role, [MembershipRole::Owner, MembershipRole::Admin], true)
            && $membership->allows(Permission::CancelOrders);
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
