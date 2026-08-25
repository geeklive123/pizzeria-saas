<?php

namespace App\Models;

use App\Enums\PrintAttemptStatus;
use App\Enums\PrinterPurpose;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['company_id', 'branch_id', 'printer_setting_id', 'kitchen_dispatch_id', 'order_id', 'purpose', 'windows_printer_name', 'copies', 'status', 'is_reprint', 'requested_by', 'attempted_at', 'error_message'])]
class PrintAttempt extends Model
{
    use BelongsToCompany, HasUlids;

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    protected function casts(): array
    {
        return [
            'purpose' => PrinterPurpose::class,
            'status' => PrintAttemptStatus::class,
            'copies' => 'integer',
            'is_reprint' => 'boolean',
            'attempted_at' => 'immutable_datetime',
        ];
    }

    public function printerSetting(): BelongsTo
    {
        return $this->belongsTo(PrinterSetting::class);
    }

    public function kitchenDispatch(): BelongsTo
    {
        return $this->belongsTo(KitchenDispatch::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
