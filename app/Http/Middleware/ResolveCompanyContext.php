<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Models\User;
use App\Support\CompanyContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveCompanyContext
{
    public function __construct(private readonly CompanyContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        abort_unless($user, Response::HTTP_UNAUTHORIZED);

        $reference = $request->route('company') ?? $request->header('X-Company-Id');

        if (! $reference && $request->hasSession()) {
            $reference = $request->session()->get('active_company_id');
        }

        $company = $reference instanceof Company
            ? $reference
            : $this->resolveCompany($user, $reference);

        if ($reference && ! $company) {
            abort(Response::HTTP_FORBIDDEN, 'You do not have access to this company.');
        }

        if (! $company) {
            return redirect()->route('context.companies');
        }

        $this->context->setForUser($user, $company);
        $request->attributes->set('company', $company);

        if ($request->hasSession()) {
            $request->session()->put('active_company_id', $company->getKey());
        }

        try {
            return $next($request);
        } finally {
            $this->context->clear();
        }
    }

    private function resolveCompany(User $user, mixed $reference): ?Company
    {
        $query = Company::query()->forUser($user)->where('is_active', true);

        if ($reference) {
            return $query->where(function ($companyQuery) use ($reference): void {
                $companyQuery->where('ulid', $reference);

                if (ctype_digit((string) $reference)) {
                    $companyQuery->orWhere('companies.id', (int) $reference);
                }
            })->first();
        }

        $companies = $query->limit(2)->get();

        return $companies->count() === 1 ? $companies->first() : null;
    }
}
