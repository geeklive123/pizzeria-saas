<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DynamicQrNotification extends Model
{
    protected $guarded = ['id'];

    public function intent(): BelongsTo
    {
        return $this->belongsTo(DynamicQrIntent::class, 'intent_id');
    }
}
