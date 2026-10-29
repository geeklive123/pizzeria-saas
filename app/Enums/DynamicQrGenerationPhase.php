<?php

namespace App\Enums;

enum DynamicQrGenerationPhase: string
{
    case Prepared = 'prepared';
    case SendArmed = 'send_armed';
    case Generated = 'generated';
    case GenerationUnknown = 'generation_unknown';
    case ErrorConfirmed = 'error_confirmed';
}
