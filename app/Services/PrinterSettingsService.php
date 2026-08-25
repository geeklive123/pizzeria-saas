<?php

namespace App\Services;

use App\Enums\PrinterPurpose;
use App\Models\Branch;
use App\Models\Company;
use App\Models\PrinterSetting;

class PrinterSettingsService
{
    public function get(Company $company, Branch $branch, PrinterPurpose $purpose): PrinterSetting
    {
        return PrinterSetting::query()
            ->where('company_id', $company->getKey())
            ->where('branch_id', $branch->getKey())
            ->where('purpose', $purpose->value)
            ->first() ?? new PrinterSetting([
                'company_id' => $company->getKey(),
                'branch_id' => $branch->getKey(),
                'purpose' => $purpose,
                'windows_printer_name' => config('thermal-printing.default_printer_name'),
                'is_active' => false,
                'auto_print' => false,
                'copies' => 1,
                'paper_width_mm' => 80,
            ]);
    }
}
