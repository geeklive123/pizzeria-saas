<?php

namespace App\Enums;

enum PrintAttemptStatus: string
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
