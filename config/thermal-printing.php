<?php

return [
    'default_printer_name' => env('THERMAL_PRINTER_DEFAULT_NAME', 'EPSON TM-T20II Receipt'),
    'powershell' => env('THERMAL_PRINTER_POWERSHELL', 'powershell.exe'),
    'raw_spooler_script' => base_path('scripts/windows-raw-print.ps1'),
    'temporary_directory' => storage_path('app/private/thermal-printing'),
    'timeout_seconds' => (int) env('THERMAL_PRINTER_TIMEOUT', 15),
];
