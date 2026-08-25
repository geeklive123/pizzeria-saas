<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Company;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ContextController extends Controller
{
    public function companies(Request $request): View|RedirectResponse
    {
        $companies = Company::query()->forUser($request->user())->where('is_active', true)->orderBy('name')->get();

        if ($companies->count() === 1) {
            $request->session()->put('active_company_id', $companies->first()->getKey());

            return redirect()->route('dashboard');
        }

        return view('context.companies', compact('companies'));
    }

    public function selectCompany(Request $request): RedirectResponse
    {
        $company = Company::query()->forUser($request->user())
            ->where('is_active', true)->where('ulid', $request->validate(['company' => ['required', 'string']])['company'])
            ->firstOrFail();
        $request->session()->put('active_company_id', $company->getKey());
        $request->session()->forget('active_branch_id');

        return redirect()->route('dashboard');
    }

    public function branches(): View|RedirectResponse
    {
        $branches = Branch::query()->forCompany($this->company())->where('is_active', true)->orderBy('name')->get();

        if ($branches->count() === 1) {
            session()->put('active_branch_id', $branches->first()->getKey());

            return redirect()->route('dashboard');
        }

        return view('context.branches', compact('branches'));
    }

    public function selectBranch(Request $request): RedirectResponse
    {
        $branch = Branch::query()->forCompany($this->company())->where('is_active', true)
            ->where('ulid', $request->validate(['branch' => ['required', 'string']])['branch'])->firstOrFail();
        $request->session()->put('active_branch_id', $branch->getKey());

        return redirect()->route('dashboard');
    }
}
