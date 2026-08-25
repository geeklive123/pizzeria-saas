<?php

namespace App\Enums;

enum ExpenseDocumentType: string
{
    case WithInvoice = 'with_invoice';
    case WithoutInvoice = 'without_invoice';
    case Receipt = 'receipt';
    case Other = 'other';
}
