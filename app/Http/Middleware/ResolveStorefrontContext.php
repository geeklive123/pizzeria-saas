<?php

namespace App\Http\Middleware;

use App\Models\Branch;
use App\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ResolveStorefrontContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = Str::lower($request->getHost());
        $domains = config('storefront.domains', []);
        $domain = is_array($domains) ? ($domains[$host] ?? []) : [];
        $companyId = data_get($domain, 'company_id', config('storefront.default.company_id'));
        $branchId = data_get($domain, 'branch_id', config('storefront.default.branch_id'));

        $company = Company::query()
            ->whereKey($companyId)
            ->where('is_active', true)
            ->first();

        abort_unless($company, Response::HTTP_NOT_FOUND, 'La tienda no está disponible.');

        $branch = Branch::query()
            ->forCompany($company)
            ->whereKey($branchId)
            ->where('is_active', true)
            ->first();

        abort_unless($branch, Response::HTTP_NOT_FOUND, 'La sucursal no está disponible.');

        $request->attributes->set('storefrontCompany', $company);
        $request->attributes->set('storefrontBranch', $branch);

        return $next($request);
    }
}
