<?php

return [
    'timezone' => env('INVENTORY_TIMEZONE', 'America/La_Paz'),
    'expiring_soon_days' => (int) env('INVENTORY_EXPIRING_SOON_DAYS', 7),
    'low_recipe_availability' => (int) env('INVENTORY_LOW_RECIPE_AVAILABILITY', 3),
];
