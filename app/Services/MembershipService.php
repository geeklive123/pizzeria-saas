<?php

namespace App\Services;

use App\Enums\MembershipRole;
use App\Models\Membership;
use Illuminate\Support\Facades\DB;

class MembershipService
{
    public function update(Membership $membership, MembershipRole $role, bool $isActive): Membership
    {
        return DB::transaction(function () use ($membership, $role, $isActive): Membership {
            $this->lockCompanyMemberships($membership);

            $lockedMembership = Membership::query()->lockForUpdate()->findOrFail($membership->getKey());
            $lockedMembership->update(['role' => $role, 'is_active' => $isActive]);

            return $lockedMembership->refresh();
        });
    }

    public function delete(Membership $membership): void
    {
        DB::transaction(function () use ($membership): void {
            $this->lockCompanyMemberships($membership);
            Membership::query()->lockForUpdate()->findOrFail($membership->getKey())->delete();
        });
    }

    private function lockCompanyMemberships(Membership $membership): void
    {
        Membership::query()
            ->where('company_id', $membership->company_id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }
}
