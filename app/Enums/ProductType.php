<?php

namespace App\Enums;

enum ProductType: string
{
    case Pizza = 'pizza';
    case Beverage = 'beverage';
    case Extra = 'extra';
    case Combo = 'combo';
    case Other = 'other';
}
