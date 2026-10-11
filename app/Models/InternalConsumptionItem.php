<?php

namespace App\Models;

use App\Enums\OrderType;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['company_id', 'branch_id', 'internal_consumption_id', 'product_variant_id', 'quantity', 'fulfillment_type', 'product_name_snapshot', 'variant_name_snapshot', 'configuration_snapshot', 'notes'])]
class InternalConsumptionItem extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'fulfillment_type' => OrderType::class,
            'configuration_snapshot' => 'array',
        ];
    }

    public function consumption(): BelongsTo
    {
        return $this->belongsTo(InternalConsumption::class, 'internal_consumption_id');
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function components(): HasMany
    {
        return $this->hasMany(InternalConsumptionComponent::class);
    }
}
