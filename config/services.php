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
        'redirect_uri' => env('QB_REDIRECT_URI', rtrim(env('APP_URL', 'http://localhost'), '/').'/api/integrations/qbo/callback'),
        'sandbox_base_url' => env('QB_SANDBOX_BASE_URL', 'https://sandbox-quickbooks.api.intuit.com'),
        'production_base_url' => env('QB_PRODUCTION_BASE_URL', 'https://quickbooks.api.intuit.com'),
        'minor_version' => env('QB_MINOR_VERSION', '75'),
    ],

    'llm' => [
        'provider' => env('LLM_PROVIDER', 'openai'),
        'api_key' => env('OPENAI_API_KEY', env('LLM_API_KEY')),
        'base_url' => env('LLM_BASE_URL', 'https://api.openai.com/v1'),
        'model' => env('LLM_MODEL', 'chat-latest'),
        'router_model' => env('LLM_ROUTER_MODEL', env('LLM_MODEL', 'chat-latest')),
        'timeout' => env('LLM_TIMEOUT', 60),
    ],

    'brevix_agent' => [
        'base_url' => env('BREVIX_AGENT_SERVICE_URL', 'http://localhost:8010'),
        'api_key' => env('BREVIX_AGENT_SERVICE_KEY'),
        'timeout' => env('BREVIX_AGENT_TIMEOUT', 60),
    ],

    'stripe' => [
        'key' => env('STRIPE_SECRET_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'price_ids' => [
            'growth' => env('STRIPE_PRICE_GROWTH'),
            'risk-advisory' => env('STRIPE_PRICE_RISK_ADVISORY'),
        ],
    ],

];
