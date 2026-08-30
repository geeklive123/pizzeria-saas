<?php

namespace App\Http\Controllers;

use App\Enums\MembershipRole;
use App\Services\DashboardService;
use App\Support\CompanyContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(DashboardService $dashboard, CompanyContext $context): View|RedirectResponse
    {
        if ($context->membership()->role === MembershipRole::Cashier) {
            return redirect()->route('sales.create');
        }

        return view('dashboard', $dashboard->data($this->company(), $this->branch()));
    }
}
