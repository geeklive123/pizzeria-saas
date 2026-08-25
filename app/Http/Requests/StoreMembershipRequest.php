<?php

namespace App\Http\Requests;

use App\Enums\MembershipRole;
use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class StoreMembershipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => mb_strtolower(trim((string) $this->input('email'))),
            'is_active' => $this->boolean('is_active'),
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(8)],
            'role' => ['required', Rule::enum(MembershipRole::class)],
            'is_active' => ['required', 'boolean'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['required', Rule::in(['inherit', 'allow', 'deny'])],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_keys((array) $this->input('permissions', [])) as $permission) {
                if (! Permission::tryFrom((string) $permission)) {
                    $validator->errors()->add('permissions', 'Existe un permiso desconocido.');
                }
            }
        }];
    }
}
