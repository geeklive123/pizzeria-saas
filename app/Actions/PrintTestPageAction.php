<?php

namespace App\Actions;

use App\Enums\Permission;
use App\Models\PrintAttempt;
use App\Models\PrinterSetting;
use App\Models\User;
use App\Services\CompanyAccessService;
use App\Services\ThermalPrintingService;

class PrintTestPageAction
{
    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly ThermalPrintingService $printing,
    ) {}

    public function execute(PrinterSetting $setting, User $user): PrintAttempt
    {
        $setting->loadMissing('company');
        $this->access->ensure($user, $setting->company, Permission::ManageCompany);

        return $this->printing->testPage($setting, $user);
    }
}
