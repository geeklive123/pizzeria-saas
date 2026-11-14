<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OnlineOrderRequestItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['unit_price' => 'decimal:2', 'subtotal' => 'decimal:2'];
    }
}
