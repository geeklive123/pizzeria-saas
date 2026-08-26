<?php

return [
    'default_printer_name' => env('THERMAL_PRINTER_DEFAULT_NAME', 'EPSON TM-T20II Receipt'),
    'powershell' => env('THERMAL_PRINTER_POWERSHELL', 'powershell.exe'),
    'raw_spooler_script' => base_path('scripts/windows-raw-print.ps1'),
    'temporary_directory' => storage_path('app/private/thermal-printing'),
    'timeout_seconds' => (int) env('THERMAL_PRINTER_TIMEOUT', 15),
    'agent' => [
        'url' => env('PRINT_AGENT_URL'),
        'token' => env('PRINT_AGENT_TOKEN'),
        'transport' => env('PRINT_AGENT_TRANSPORT', 'windows_raw'),
        'kitchen_printer' => env('KITCHEN_PRINTER', env('THERMAL_PRINTER_DEFAULT_NAME', 'EPSON TM-T20II Receipt')),
        'customer_printer' => env('CUSTOMER_PRINTER', env('THERMAL_PRINTER_DEFAULT_NAME', 'EPSON TM-T20II Receipt')),
        'poll_interval_seconds' => (int) env('POLL_INTERVAL_SECONDS', 3),
        'request_timeout_seconds' => (int) env('PRINT_AGENT_REQUEST_TIMEOUT', 20),
        'claim_timeout_seconds' => (int) env('PRINT_AGENT_CLAIM_TIMEOUT', 120),
        'retry_delay_seconds' => (int) env('PRINT_AGENT_RETRY_DELAY', 15),
        'online_threshold_seconds' => (int) env('PRINT_AGENT_ONLINE_THRESHOLD', 30),
        'output_directory' => env('PRINT_AGENT_OUTPUT_DIRECTORY', storage_path('print-agent-output')),
        'state_file' => env('PRINT_AGENT_STATE_FILE', storage_path('app/private/print-agent-state.json')),
        'require_https' => env('PRINT_AGENT_REQUIRE_HTTPS', true),
    ],
];
