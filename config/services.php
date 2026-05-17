<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'quickbooks' => [
        'redirect_uri' => env('QB_REDIRECT_URI', rtrim(env('APP_URL', 'http://localhost'), '/') . '/api/integrations/qbo/callback'),
        'sandbox_base_url' => env('QB_SANDBOX_BASE_URL', 'https://sandbox-quickbooks.api.intuit.com'),
        'production_base_url' => env('QB_PRODUCTION_BASE_URL', 'https://quickbooks.api.intuit.com'),
        'minor_version' => env('QB_MINOR_VERSION', '75'),
    ],

    'brevix_agent' => [
        'base_url' => env('BREVIX_AGENT_SERVICE_URL', 'http://localhost:8010'),
        'api_key' => env('BREVIX_AGENT_SERVICE_KEY'),
        'timeout' => env('BREVIX_AGENT_TIMEOUT', 60),
    ],

];
