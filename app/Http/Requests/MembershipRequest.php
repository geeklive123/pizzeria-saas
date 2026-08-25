<?php

namespace App\Http\Requests;

use App\Enums\MembershipRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MembershipRequest extends FormRequest
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
        return [
            'role' => ['required', Rule::enum(MembershipRole::class)],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
