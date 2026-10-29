<?php

namespace App\Enums;

enum DynamicQrStatus: string
{
    case Creating = 'creating';
    case Pending = 'pending';
    case Failed = 'failed';
    case GenerationUnknown = 'generation_unknown';
    case Cancelled = 'cancelled';
    case Paid = 'paid';
    case ReconciliationRequired = 'reconciliation_required';
}
