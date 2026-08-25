<?php

namespace App\Models;

use App\Enums\MembershipRole;
use App\Enums\Permission;
use Database\Factories\MembershipFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

#[Fillable(['company_id', 'user_id', 'role', 'is_active'])]
class Membership extends Pivot
{
    /** @use HasFactory<MembershipFactory> */
    use HasFactory;

    protected $table = 'memberships';

    public $incrementing = true;

    protected static function booted(): void
    {
        static::saving(function (Membership $membership): void {
            if ($membership->exists && $membership->wasActiveOwner() && ! $membership->isActiveOwner()) {
                $membership->ensureAnotherActiveOwnerExists();
            }
        });

        static::deleting(function (Membership $membership): void {
            if ($membership->isActiveOwner()) {
                $membership->ensureAnotherActiveOwnerExists();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'role' => MembershipRole::class,
            'is_active' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function permissionOverrides(): HasMany
    {
        return $this->hasMany(MembershipPermissionOverride::class, 'membership_id', 'id');
    }

    public function allows(Permission $permission): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->role === MembershipRole::Owner) {
            return true;
        }

        $override = $this->relationLoaded('permissionOverrides')
            ? $this->permissionOverrides->first(fn (MembershipPermissionOverride $item): bool => $item->permission === $permission)
            : $this->permissionOverrides()->where('permission', $permission->value)->first();

        return $override?->allowed ?? $this->role->allows($permission);
    }

    public function isActiveOwner(): bool
    {
        return $this->is_active && $this->role === MembershipRole::Owner;
    }

    private function wasActiveOwner(): bool
    {
        return (bool) $this->getRawOriginal('is_active')
            && $this->getRawOriginal('role') === MembershipRole::Owner->value;
    }

    private function ensureAnotherActiveOwnerExists(): void
    {
        $anotherOwnerExists = static::query()
            ->where('company_id', $this->company_id)
            ->whereKeyNot($this->getKey())
            ->where('role', MembershipRole::Owner->value)
            ->where('is_active', true)
            ->exists();

        if (! $anotherOwnerExists) {
            throw new DomainException('A company must retain at least one active owner.');
        }
    }
}
