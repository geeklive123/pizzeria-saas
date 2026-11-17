<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class OnlineQrDisposition extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Las disposiciones registradas son inmutables.'));
        static::deleting(fn () => throw new LogicException('Las disposiciones registradas no se eliminan.'));
    }
}
