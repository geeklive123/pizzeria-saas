<?php

namespace App\Http\Middleware;

use App\Models\Branch;
use App\Support\BranchContext;
use App\Support\CompanyContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveBranchContext
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly BranchContext $branchContext,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $company = $this->companyContext->company();
        $reference = $request->hasSession() ? $request->session()->get('active_branch_id') : null;
        $branch = $reference
            ? Branch::query()->forCompany($company)->whereKey($reference)->where('is_active', true)->first()
            : null;

        if (! $branch) {
            $branches = Branch::query()->forCompany($company)->where('is_active', true)->limit(2)->get();
            $branch = $branches->count() === 1 ? $branches->first() : null;
        }

        if (! $branch) {
            return redirect()->route('context.branch');
        }

        $this->branchContext->set($company, $branch);
        $request->attributes->set('branch', $branch);
        $request->session()->put('active_branch_id', $branch->getKey());

        try {
            return $next($request);
        } finally {
            $this->branchContext->clear();
        }
    }
}
