<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class BankQrSetting extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array', 'enabled' => 'boolean', 'technical_logging' => 'boolean', 'due_days' => 'integer'];
    }

    public static function forOrder(Order $order): ?self
    {
        return self::forLocation($order->company_id, $order->branch_id);
    }

    public static function forBranch(Company $company, Branch $branch): ?self
    {
        return self::forLocation($company->id, $branch->id);
    }

    private static function forLocation(int $companyId, int $branchId): ?self
    {
        return self::query()->forCompany($companyId)->whereNotNull('active_scope')
            ->where(fn ($query) => $query->where('branch_id', $branchId)->orWhereNull('branch_id'))
            ->orderByRaw('branch_id IS NULL')->first();
    }
}
