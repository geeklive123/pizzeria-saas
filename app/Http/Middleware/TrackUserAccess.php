<?php

namespace App\Http\Middleware;

use App\Services\UserAccessLogService;
use App\Support\BranchContext;
use App\Support\CompanyContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrackUserAccess
{
    public function __construct(
        private readonly UserAccessLogService $accessLogs,
        private readonly CompanyContext $companyContext,
        private readonly BranchContext $branchContext,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->accessLogs->trackActivity(
            $request,
            $this->companyContext->company(),
            $this->branchContext->branch(),
            $this->companyContext->membership(),
        );

        return $next($request);
    }
}
