<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['company_id', 'branch_id', 'name', 'token_hash', 'is_active', 'last_seen_at', 'last_printed_at', 'revoked_at'])]
class PrintAgent extends Model
{
    use BelongsToCompany, HasUlids;

    protected $hidden = ['token_hash'];

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_seen_at' => 'immutable_datetime',
            'last_printed_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function printAttempts(): HasMany
    {
        return $this->hasMany(PrintAttempt::class, 'claimed_by_agent_id');
    }

    public function scopeForBranch(Builder $query, Branch|int $branch): Builder
    {
        return $query->where('branch_id', $branch instanceof Branch ? $branch->getKey() : $branch);
    }
}
