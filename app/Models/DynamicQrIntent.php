<?php

namespace App\Models;

use App\Enums\DynamicQrStatus;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use LogicException;

class DynamicQrIntent extends Model
{
    use BelongsToCompany, HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['qr_image', 'reconciliation_data'];

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public static function usesBankFlow(Order $order): bool
    {
        return config('baneco.enabled') || self::query()->forCompany($order->company_id)->where('branch_id', $order->branch_id)
            ->where('order_id', $order->id)
            ->where(fn ($query) => $query->whereNotNull('active_order_id')->orWhere('status', DynamicQrStatus::ReconciliationRequired))->exists();
    }

    protected function casts(): array
    {
        return ['status' => DynamicQrStatus::class, 'amount' => 'decimal:2', 'expires_at' => 'immutable_datetime', 'last_checked_at' => 'immutable_datetime', 'confirmed_at' => 'immutable_datetime', 'ticket_enqueued_at' => 'immutable_datetime', 'reconciliation_data' => 'array'];
    }

    protected function getDirtyForUpdate()
    {
        $dirty = parent::getDirtyForUpdate();
        if ($dirty !== [] && ! array_key_exists('expires_at', $dirty)) {
            // Preserve the stored value even on legacy MySQL schemas with ON UPDATE CURRENT_TIMESTAMP.
            $dirty['expires_at'] = DB::raw('expires_at');
        }

        return $dirty;
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('QR intents preserve financial audit history.'));
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function cashSession(): BelongsTo
    {
        return $this->belongsTo(CashSession::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function kitchenDispatch(): BelongsTo
    {
        return $this->belongsTo(KitchenDispatch::class);
    }

    public function setting(): BelongsTo
    {
        return $this->belongsTo(BankQrSetting::class, 'bank_qr_setting_id');
    }
}
