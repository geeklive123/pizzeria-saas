<?php

namespace App\Services;

use App\Enums\LogoutReason;
use App\Enums\MembershipRole;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use App\Models\UserAccessLog;
use Illuminate\Http\Request;

class UserAccessLogService
{
    private const SESSION_KEY = 'user_access_log_ids';

    private const ACTIVITY_KEY = 'user_access_log_activity_at';

    private const ACTIVITY_THROTTLE_SECONDS = 180;

    public function startAfterLogin(Request $request, User $user): void
    {
        $memberships = Membership::query()
            ->with('company')
            ->where('user_id', $user->getKey())
            ->where('is_active', true)
            ->whereIn('role', $this->trackedRoleValues())
            ->whereHas('company', fn ($query) => $query->where('is_active', true))
            ->limit(2)
            ->get();

        if ($memberships->count() !== 1) {
            return;
        }

        $membership = $memberships->first();
        $branches = Branch::query()->forCompany($membership->company_id)->where('is_active', true)->limit(2)->get();

        $this->start($request, $user, $membership->company, $branches->count() === 1 ? $branches->first() : null, $membership->role);
    }

    public function trackActivity(Request $request, Company $company, Branch $branch, Membership $membership): void
    {
        if (! in_array($membership->role, $this->trackedRoles(), true)) {
            return;
        }

        $log = $this->current($request, $company->getKey());

        if (! $log) {
            $log = $this->start($request, $request->user(), $company, $branch, $membership->role);
        } elseif ($log->branch_id === null) {
            $log->forceFill(['branch_id' => $branch->getKey()])->save();
        }

        $lastTouch = (int) $request->session()->get(self::ACTIVITY_KEY.'.'.$company->getKey(), 0);
        if (now()->timestamp - $lastTouch < self::ACTIVITY_THROTTLE_SECONDS) {
            return;
        }

        $log->forceFill(['last_activity_at' => now()])->save();
        $request->session()->put(self::ACTIVITY_KEY.'.'.$company->getKey(), now()->timestamp);
    }

    public function finish(Request $request, User $user, LogoutReason $reason, ?int $companyId = null): void
    {
        $ids = (array) $request->session()->get(self::SESSION_KEY, []);
        $selected = $companyId === null ? $ids : array_intersect_key($ids, [(string) $companyId => true, $companyId => true]);

        if ($selected !== []) {
            UserAccessLog::query()
                ->where('user_id', $user->getKey())
                ->whereIn('id', array_values($selected))
                ->whereNull('logged_out_at')
                ->update([
                    'logged_out_at' => now(),
                    'last_activity_at' => now(),
                    'logout_reason' => $reason->value,
                    'updated_at' => now(),
                ]);
        }

        if ($companyId === null) {
            $request->session()->forget([self::SESSION_KEY, self::ACTIVITY_KEY]);

            return;
        }

        unset($ids[$companyId], $ids[(string) $companyId]);
        $request->session()->put(self::SESSION_KEY, $ids);
        $request->session()->forget(self::ACTIVITY_KEY.'.'.$companyId);
    }

    private function start(Request $request, User $user, Company $company, ?Branch $branch, MembershipRole $role): UserAccessLog
    {
        $existing = $this->current($request, $company->getKey());
        if ($existing) {
            return $existing;
        }

        $now = now();
        $log = UserAccessLog::query()->create([
            'company_id' => $company->getKey(),
            'branch_id' => $branch?->getKey(),
            'user_id' => $user->getKey(),
            'role' => $role,
            'session_hash' => hash('sha256', $request->session()->getId()),
            'logged_in_at' => $now,
            'last_activity_at' => $now,
        ]);

        $ids = (array) $request->session()->get(self::SESSION_KEY, []);
        $ids[$company->getKey()] = $log->getKey();
        $request->session()->put(self::SESSION_KEY, $ids);
        $request->session()->put(self::ACTIVITY_KEY.'.'.$company->getKey(), $now->timestamp);

        return $log;
    }

    private function current(Request $request, int $companyId): ?UserAccessLog
    {
        $id = $request->session()->get(self::SESSION_KEY.'.'.$companyId);

        if (! $id) {
            return null;
        }

        return UserAccessLog::query()
            ->whereKey($id)
            ->where('company_id', $companyId)
            ->where('user_id', $request->user()?->getKey())
            ->whereNull('logged_out_at')
            ->first();
    }

    /** @return list<MembershipRole> */
    private function trackedRoles(): array
    {
        return [MembershipRole::Cashier, MembershipRole::Kitchen];
    }

    /** @return list<string> */
    private function trackedRoleValues(): array
    {
        return array_map(fn (MembershipRole $role): string => $role->value, $this->trackedRoles());
    }
}
