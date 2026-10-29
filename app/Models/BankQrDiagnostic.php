<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankQrDiagnostic extends Model
{
    use BelongsToCompany, HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['qr_image', 'original_qr_image'];

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'expires_at' => 'immutable_datetime', 'original_qr_image' => 'encrypted'];
    }

    public function setting(): BelongsTo
    {
        return $this->belongsTo(BankQrSetting::class, 'bank_qr_setting_id');
    }
}
