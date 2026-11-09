<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class FinancialPaymentAttempt extends Model
{
    use BelongsToCompany, HasUlids;

    protected $guarded = ['id'];

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'received_amount' => 'decimal:2', 'revision' => 'integer', 'expected_financial_version' => 'integer'];
    }
}
