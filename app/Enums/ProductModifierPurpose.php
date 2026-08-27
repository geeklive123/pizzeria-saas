<?php

namespace App\Enums;

enum ProductModifierPurpose: string
{
    case OrderModifier = 'order_modifier';
    case ToppingCatalog = 'topping_catalog';
}
