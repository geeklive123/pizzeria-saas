<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

class StorefrontVisibilityService
{
    public function applyCategoryVisibility(Builder|Relation $query): Builder|Relation
    {
        $names = $this->strings(config('storefront.visibility.category_names', []));
        $ulids = $this->strings(config('storefront.visibility.category_ulids', []));

        if ($names === [] && $ulids === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $visible) use ($names, $ulids): void {
            if ($names !== []) {
                $visible->whereIn('name', $names);
            }

            if ($ulids !== []) {
                $method = $names === [] ? 'whereIn' : 'orWhereIn';
                $visible->{$method}('ulid', $ulids);
            }
        });
    }

    public function applyProductVisibility(Builder|Relation $query): Builder|Relation
    {
        $names = $this->strings(config('storefront.visibility.product_names', []));
        $ulids = $this->strings(config('storefront.visibility.product_ulids', []));

        if ($names === [] && $ulids === []) {
            return $query;
        }

        return $query->where(function (Builder $visible) use ($names, $ulids): void {
            if ($names !== []) {
                $visible->whereIn('name', $names);
            }

            if ($ulids !== []) {
                $method = $names === [] ? 'whereIn' : 'orWhereIn';
                $visible->{$method}('ulid', $ulids);
            }
        });
    }

    /** @return list<string> */
    private function strings(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return collect($values)
            ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            ->map(fn (string $value): string => trim($value))
            ->unique()
            ->values()
            ->all();
    }
}
