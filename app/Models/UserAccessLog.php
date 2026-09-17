<?php

namespace App\Models;

use App\Enums\LogoutReason;
use App\Enums\MembershipRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['company_id', 'branch_id', 'user_id', 'role', 'session_hash', 'logged_in_at', 'logged_out_at', 'last_activity_at', 'logout_reason'])]
class UserAccessLog extends Model
{
    protected function casts(): array
    {
        return [
            'role' => MembershipRole::class,
            'logout_reason' => LogoutReason::class,
            'logged_in_at' => 'datetime',
            'logged_out_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForCompany(Builder $query, Company|int $company): Builder
    {
        return $query->where('company_id', $company instanceof Company ? $company->getKey() : $company);
    }

    public function getDurationMinutesAttribute(): int
    {
        return (int) $this->logged_in_at->diffInMinutes($this->logged_out_at ?? now());
    }
}
