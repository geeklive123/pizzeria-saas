<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\MembershipRole;
use App\Enums\Permission;
use Database\Factories\UserFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected static function booted(): void
    {
        static::deleting(function (User $user): void {
            $activeOwnerMemberships = $user->memberships()
                ->where('role', MembershipRole::Owner->value)
                ->where('is_active', true)
                ->get();

            foreach ($activeOwnerMemberships as $membership) {
                $anotherOwnerExists = Membership::query()
                    ->where('company_id', $membership->company_id)
                    ->where('user_id', '!=', $user->getKey())
                    ->where('role', MembershipRole::Owner->value)
                    ->where('is_active', true)
                    ->exists();

                if (! $anotherOwnerExists) {
                    throw new DomainException('A company must retain at least one active owner.');
                }
            }
        });
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'created_by');
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'created_by');
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'memberships')
            ->using(Membership::class)
            ->withPivot(['id', 'role', 'is_active'])
            ->withTimestamps();
    }

    public function membershipFor(Company|int $company): ?Membership
    {
        $companyId = $company instanceof Company ? $company->getKey() : $company;

        return $this->memberships()
            ->with('permissionOverrides')
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->first();
    }

    public function hasRoleForCompany(MembershipRole $role, Company|int $company): bool
    {
        return $this->membershipFor($company)?->role === $role;
    }

    public function canForCompany(Permission $permission, Company|int $company): bool
    {
        return $this->membershipFor($company)?->allows($permission) ?? false;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
