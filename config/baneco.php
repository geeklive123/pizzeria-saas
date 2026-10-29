<?php

return [
    'enabled' => env('BANECO_ENABLED', false),
    'diagnostics_enabled' => env('BANECO_DIAGNOSTICS_ENABLED', false),
    'base_url' => env('BANECO_BASE_URL', 'https://apimkt.baneco.com.bo/ApiGateway/'),
    'webhook_enabled' => env('BANECO_WEBHOOK_ENABLED', false),
    'poll_seconds' => 6,
];
