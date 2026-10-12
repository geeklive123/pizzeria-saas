<?php

namespace App\Enums;

enum InternalConsumptionReason: string
{
    case Marketing = 'marketing';
    case PhotoVideo = 'photo_video';
    case Tasting = 'tasting';
    case Staff = 'staff';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Marketing => 'Publicidad / Marketing',
            self::PhotoVideo => 'Fotografía / Video',
            self::Tasting => 'Degustación',
            self::Staff => 'Consumo del personal',
            self::Other => 'Otro',
        };
    }
}
