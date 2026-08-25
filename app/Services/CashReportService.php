<?php

namespace App\Services;

use App\Data\ReportDateRange;
use App\Models\Branch;
use App\Models\CashSession;
use App\Models\Company;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class CashReportService
{
    public function __construct(private readonly CashSessionSummaryService $summary) {}

    public function paginate(Company $company, Branch $branch, ReportDateRange $range): LengthAwarePaginator
    {
        $sessions = $this->query($company, $branch, $range)
            ->with(['cashRegister:id,name', 'openedBy:id,name', 'closedBy:id,name', 'payments.order:id,status', 'movements.createdBy:id,name', 'movements.authorizedBy:id,name'])
            ->latest('opened_at')->paginate(config('reports.per_page'))->withQueryString();
        $sessions->getCollection()->each(fn (CashSession $session) => $this->decorate($session));

        return $sessions;
    }

    public function detail(Company $company, Branch $branch, string $ulid): CashSession
    {
        $session = CashSession::query()->forCompany($company)->forBranch($branch)->where('ulid', $ulid)
            ->with(['branch', 'cashRegister', 'openedBy', 'closedBy', 'payments.receivedBy', 'payments.order:id,status', 'movements.createdBy', 'movements.authorizedBy'])->firstOrFail();

        return $this->decorate($session);
    }

    public function query(Company $company, Branch $branch, ReportDateRange $range): Builder
    {
        return CashSession::query()->forCompany($company)->forBranch($branch)
            ->whereBetween('opened_at', [$range->fromUtc(), $range->toUtc()]);
    }

    private function decorate(CashSession $session): CashSession
    {
        $session->setAttribute('report_summary', $this->summary->calculate($session));
        $end = $session->closed_at ?? now();
        $session->setAttribute('duration_minutes', intdiv((int) $session->opened_at->diffInSeconds($end), 60));

        return $session;
    }
}
