<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Company;
use App\Support\BranchContext;
use App\Support\CompanyContext;

abstract class Controller
{
    protected function company(): Company
    {
        return app(CompanyContext::class)->company();
    }

    protected function branch(): Branch
    {
        return app(BranchContext::class)->branch();
    }
}
