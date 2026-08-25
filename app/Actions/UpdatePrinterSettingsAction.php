<?php

namespace App\Actions;

use App\Enums\Permission;
use App\Enums\PrinterPurpose;
use App\Models\Branch;
use App\Models\Company;
use App\Models\PrinterSetting;
use App\Models\User;
use App\Services\CompanyAccessService;
use DomainException;
use Illuminate\Support\Facades\DB;

class UpdatePrinterSettingsAction
{
    public function __construct(private readonly CompanyAccessService $access) {}

    public function execute(Company $company, Branch $branch, User $user, array $settings): void
    {
        $this->access->ensure($user, $company, Permission::ManageCompany);
        if ($branch->company_id !== $company->getKey()) {
            throw new DomainException('La sucursal no pertenece a la empresa activa.');
        }

        DB::transaction(function () use ($company, $branch, $settings): void {
            foreach (PrinterPurpose::cases() as $purpose) {
                $data = $settings[$purpose->value];
                PrinterSetting::query()->updateOrCreate(
                    [
                        'company_id' => $company->getKey(),
                        'branch_id' => $branch->getKey(),
                        'purpose' => $purpose->value,
                    ],
                    [
                        'windows_printer_name' => $data['windows_printer_name'],
                        'is_active' => (bool) ($data['is_active'] ?? false),
                        'auto_print' => $purpose === PrinterPurpose::Kitchen && (bool) ($data['auto_print'] ?? false),
                        'copies' => $data['copies'],
                        'paper_width_mm' => 80,
                    ],
                );
            }
        });
    }
}
