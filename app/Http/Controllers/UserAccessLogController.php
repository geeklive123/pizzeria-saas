<?php

namespace App\Http\Controllers;

use App\Enums\MembershipRole;
use App\Http\Requests\UserAccessLogFilterRequest;
use App\Models\Branch;
use App\Models\User;
use App\Models\UserAccessLog;
use App\Services\ReportDateRangeService;
use App\Services\UserAccessLogQueryService;
use App\Support\CompanyContext;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class UserAccessLogController extends Controller
{
    public function __invoke(
        UserAccessLogFilterRequest $request,
        ReportDateRangeService $ranges,
        UserAccessLogQueryService $logs,
    ): View {
        $membership = app(CompanyContext::class)->membership();
        abort_unless(in_array($membership->role, [MembershipRole::Owner, MembershipRole::Admin], true), Response::HTTP_FORBIDDEN);

        $filters = $request->validated();
        $range = $ranges->from($filters);
        $company = $this->company();
        $userIds = UserAccessLog::query()->forCompany($company)->distinct()->pluck('user_id');

        return view('user-access-logs.index', [
            'logs' => $logs->paginate($company, $range, $filters),
            'range' => $range,
            'filters' => $filters,
            'users' => User::query()->whereIn('id', $userIds)->orderBy('name')->get(['id', 'name']),
            'branches' => Branch::query()->forCompany($company)->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
