<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['company_id', 'branch_id', 'internal_consumption_id', 'reason', 'cancelled_by', 'cancelled_at'])]
class InternalConsumptionCancellation extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return ['cancelled_at' => 'immutable_datetime'];
    }

    public function consumption(): BelongsTo
    {
        return $this->belongsTo(InternalConsumption::class, 'internal_consumption_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
