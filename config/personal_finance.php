<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Personal Finance Analyzer
    |--------------------------------------------------------------------------
    |
    | Keep the default disabled. Local routes are available only in configured
    | local environments; deployed routes must also pass admin authorization.
    |
    */

    'enabled' => env('PERSONAL_FINANCE_ENABLED', env('PERSONAL_FINANCE_LOCAL_ENABLED', false)),

    'route_environments' => array_values(array_filter(array_map(
        'trim',
        explode(',', env('PERSONAL_FINANCE_ROUTE_ENVIRONMENTS', 'local,testing'))
    ))),

    'statement_disk' => env('PERSONAL_FINANCE_STATEMENT_DISK', 'local'),

    'statement_prefix' => trim(env('PERSONAL_FINANCE_STATEMENT_PREFIX', ''), '/'),

    'statement_path' => env('PERSONAL_FINANCE_STATEMENT_PATH', storage_path('personal')),

    'export_path' => env('PERSONAL_FINANCE_EXPORT_PATH', storage_path('app/private/personal-finance/exports')),

    'pdftotext_path' => env('PERSONAL_FINANCE_PDFTOTEXT_PATH', '/usr/local/bin/pdftotext'),
];
