<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Local Personal Finance Analyzer
    |--------------------------------------------------------------------------
    |
    | This feature is intentionally local-only. Keep the default disabled and
    | enable it only in a local .env when working with personal bank records.
    |
    */

    'enabled' => env('PERSONAL_FINANCE_LOCAL_ENABLED', false),

    'route_environments' => array_values(array_filter(array_map(
        'trim',
        explode(',', env('PERSONAL_FINANCE_ROUTE_ENVIRONMENTS', 'local,testing'))
    ))),

    'statement_path' => env('PERSONAL_FINANCE_STATEMENT_PATH', storage_path('personal')),

    'export_path' => env('PERSONAL_FINANCE_EXPORT_PATH', storage_path('app/private/personal-finance/exports')),

    'pdftotext_path' => env('PERSONAL_FINANCE_PDFTOTEXT_PATH', '/usr/local/bin/pdftotext'),
];
