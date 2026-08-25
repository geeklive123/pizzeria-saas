<?php

namespace App\Http\Controllers;

use App\Data\ReportDateRange;
use App\Http\Requests\ReportExportRequest;
use App\Http\Requests\ReportFilterRequest;
use App\Services\CashReportService;
use App\Services\ExpenseReportService;
use App\Services\InventoryReportService;
use App\Services\ProfitabilityReportService;
use App\Services\PurchaseReportService;
use App\Services\ReportDateRangeService;
use App\Services\ReportExportService;
use App\Services\ReportFilterOptionsService;
use App\Services\ReportsOverviewService;
use App\Services\SalesReportService;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(private readonly ReportFilterOptionsService $filterOptions) {}

    public function index(ReportFilterRequest $request, ReportDateRangeService $ranges, ReportsOverviewService $reports): View
    {
        Gate::authorize('reports.financial');
        [$filters, $range] = $this->filters($request, $ranges);

        return $this->view('summary', $range, $filters, $reports->data($this->company(), $this->branch(), $range, $filters));
    }

    public function sales(ReportFilterRequest $request, ReportDateRangeService $ranges, SalesReportService $reports): View
    {
        Gate::authorize('reports.view');
        [$filters, $range] = $this->filters($request, $ranges);

        return $this->view('sales', $range, $filters, ['sales' => $reports->data($this->company(), $this->branch(), $range, $filters)]);
    }

    public function products(ReportFilterRequest $request, ReportDateRangeService $ranges, SalesReportService $reports): View
    {
        Gate::authorize('reports.view');
        [$filters, $range] = $this->filters($request, $ranges);

        return $this->view('products', $range, $filters, ['sales' => $reports->data($this->company(), $this->branch(), $range, $filters)]);
    }

    public function cash(ReportFilterRequest $request, ReportDateRangeService $ranges, CashReportService $reports): View
    {
        Gate::authorize('reports.view');
        [$filters, $range] = $this->filters($request, $ranges);

        return $this->view('cash', $range, $filters, ['sessions' => $reports->paginate($this->company(), $this->branch(), $range)]);
    }

    public function cashDetail(string $session, CashReportService $reports): View
    {
        Gate::authorize('reports.view');

        return view('reports.cash-detail', ['session' => $reports->detail($this->company(), $this->branch(), $session)]);
    }

    public function expenses(ReportFilterRequest $request, ReportDateRangeService $ranges, ExpenseReportService $reports): View
    {
        Gate::authorize('reports.financial');
        [$filters, $range] = $this->filters($request, $ranges);

        return $this->view('expenses', $range, $filters, ['expenses' => $reports->data($this->company(), $this->branch(), $range, $filters), 'expenseRows' => $reports->paginate($this->company(), $this->branch(), $range, $filters)]);
    }

    public function purchases(ReportFilterRequest $request, ReportDateRangeService $ranges, PurchaseReportService $reports): View
    {
        Gate::authorize('reports.financial');
        [$filters, $range] = $this->filters($request, $ranges);

        return $this->view('purchases', $range, $filters, ['purchases' => $reports->data($this->company(), $this->branch(), $range), 'purchaseRows' => $reports->paginate($this->company(), $this->branch(), $range)]);
    }

    public function inventory(ReportFilterRequest $request, ReportDateRangeService $ranges, InventoryReportService $reports): View
    {
        Gate::authorize('reports.financial');
        [$filters, $range] = $this->filters($request, $ranges);

        return $this->view('inventory', $range, $filters, ['inventory' => $reports->inventory($this->company(), $this->branch(), $filters['sort'] ?? 'name'), 'consumption' => $reports->consumption($this->company(), $this->branch(), $range)]);
    }

    public function waste(ReportFilterRequest $request, ReportDateRangeService $ranges, InventoryReportService $reports): View
    {
        Gate::authorize('reports.financial');
        [$filters, $range] = $this->filters($request, $ranges);

        return $this->view('waste', $range, $filters, ['waste' => $reports->waste($this->company(), $this->branch(), $range)]);
    }

    public function profitability(ReportFilterRequest $request, ReportDateRangeService $ranges, ProfitabilityReportService $reports): View
    {
        Gate::authorize('reports.financial');
        [$filters, $range] = $this->filters($request, $ranges);

        return $this->view('profitability', $range, $filters, ['profitability' => $reports->data($this->company(), $this->branch(), $range, $filters)]);
    }

    public function export(ReportExportRequest $request, ReportDateRangeService $ranges, ReportExportService $exports): StreamedResponse
    {
        Gate::authorize('reports.export');
        $filters = $request->validated();
        $range = $ranges->from($filters);

        return $exports->download($filters['report'], $this->company(), $this->branch(), $range, $filters);
    }

    private function filters(ReportFilterRequest $request, ReportDateRangeService $ranges): array
    {
        $filters = $request->validated();

        return [$filters, $ranges->from($filters)];
    }

    private function view(string $section, ReportDateRange $range, array $filters, array $data): View
    {
        return view('reports.index', array_merge($data, $this->filterOptions->for($this->company(), $section), [
            'section' => $section,
            'range' => $range,
            'filters' => $filters,
        ]));
    }
}
