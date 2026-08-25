<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Company;
use LogicException;

class BranchContext
{
    private ?Branch $branch = null;

    public function set(Company $company, Branch $branch): void
    {
        if ((int) $branch->company_id !== (int) $company->getKey() || ! $branch->is_active) {
            throw new LogicException('The active branch is not available for this company.');
        }

        $this->branch = $branch;
    }

    public function clear(): void
    {
        $this->branch = null;
    }

    public function hasBranch(): bool
    {
        return $this->branch !== null;
    }

    public function branch(): Branch
    {
        return $this->branch ?? throw new LogicException('No active branch has been resolved.');
    }
}
