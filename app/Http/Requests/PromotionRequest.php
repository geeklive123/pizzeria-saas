<?php

namespace App\Http\Requests;

use App\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PromotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $selectionGroups = collect($this->input('selection_groups', []))->map(fn (array $group): array => [
            ...$group,
            'allow_repeated_flavors' => filter_var($group['allow_repeated_flavors'] ?? false, FILTER_VALIDATE_BOOL),
            'allow_fusions' => filter_var($group['allow_fusions'] ?? false, FILTER_VALIDATE_BOOL),
            'allow_extras' => filter_var($group['allow_extras'] ?? false, FILTER_VALIDATE_BOOL),
            'allow_removals' => filter_var($group['allow_removals'] ?? false, FILTER_VALIDATE_BOOL),
        ])->all();
        $this->merge([
            'is_active' => $this->boolean('is_active'),
            'starts_at' => filled($this->input('starts_at')) ? $this->input('starts_at') : null,
            'ends_at' => filled($this->input('ends_at')) ? $this->input('ends_at') : null,
            'components' => array_values(array_filter($this->input('components', []), fn ($component): bool => filled($component['inventory_item_ulid'] ?? null))),
            'selection_groups' => array_values(array_filter($selectionGroups, fn ($group): bool => filled($group['category_ulid'] ?? null))),
        ]);
    }

    public function rules(): array
    {
        $companyId = app(CompanyContext::class)->companyId();

        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'price' => ['required', 'decimal:0,2', 'gte:0'],
            'is_active' => ['required', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'components' => ['required_without:selection_groups', 'array'],
            'components.*.inventory_item_ulid' => [
                'required', 'string', 'distinct',
                Rule::exists('inventory_items', 'ulid')->where('company_id', $companyId)->where('is_active', true),
            ],
            'components.*.quantity' => ['required', 'decimal:0,3', 'gt:0'],
            'selection_groups' => ['required_without:components', 'array'],
            'selection_groups.*.ulid' => ['nullable', 'string'],
            'selection_groups.*.category_ulid' => [
                'required', 'string',
                Rule::exists('categories', 'ulid')->where('company_id', $companyId)->where('is_active', true),
            ],
            'selection_groups.*.size_key' => ['required', Rule::in(['personal', 'mediana', 'familiar'])],
            'selection_groups.*.quantity' => ['required', 'integer', 'min:1', 'max:12'],
            'selection_groups.*.allow_repeated_flavors' => ['required', 'boolean'],
            'selection_groups.*.allow_fusions' => ['required', 'boolean'],
            'selection_groups.*.allow_extras' => ['required', 'boolean'],
            'selection_groups.*.allow_removals' => ['required', 'boolean'],
        ];
    }
}
