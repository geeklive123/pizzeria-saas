<?php

namespace App\Services;

use App\Data\ReportDateRange;
use App\Models\Branch;
use App\Models\Company;

class ReportsOverviewService
{
    public function __construct(
        private readonly SalesReportService $sales,
        private readonly ExpenseReportService $expenses,
        private readonly PurchaseReportService $purchases,
        private readonly InventoryReportService $inventory,
        private readonly ProfitabilityReportService $profitability,
        private readonly UserOperationsReportService $users,
    ) {}

    public function data(Company $company, Branch $branch, ReportDateRange $range, array $filters = []): array
    {
        return [
            'sales' => $this->sales->data($company, $branch, $range, $filters),
            'expenses' => $this->expenses->data($company, $branch, $range, $filters),
            'purchases' => $this->purchases->data($company, $branch, $range),
            'waste' => $this->inventory->waste($company, $branch, $range),
            'inventory' => $this->inventory->inventory($company, $branch, $filters['sort'] ?? 'name'),
            'profitability' => $this->profitability->data($company, $branch, $range, $filters),
            'users' => $this->users->data($company, $branch, $range),
        ];
    }
}
