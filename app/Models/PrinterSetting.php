<?php

namespace App\Models;

use App\Enums\PrinterPurpose;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['company_id', 'branch_id', 'purpose', 'windows_printer_name', 'is_active', 'auto_print', 'copies', 'paper_width_mm'])]
class PrinterSetting extends Model
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
            'is_active' => 'boolean',
            'auto_print' => 'boolean',
            'copies' => 'integer',
            'paper_width_mm' => 'integer',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(PrintAttempt::class);
    }
}
