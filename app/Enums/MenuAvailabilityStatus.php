<?php

namespace App\Enums;

enum MenuAvailabilityStatus: string
{
    case Available = 'available';
    case Limited = 'limited';
    case Unavailable = 'unavailable';
    case Untracked = 'untracked';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Disponible',
            self::Limited => 'Stock limitado',
            self::Unavailable => 'Sin stock',
            self::Untracked => 'Sin control de stock',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Available => 'bg-emerald-100 text-emerald-700',
            self::Limited => 'bg-amber-100 text-amber-700',
            self::Unavailable => 'bg-red-100 text-red-700',
            self::Untracked => 'bg-slate-100 text-slate-600',
        };
    }
}
