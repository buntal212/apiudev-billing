<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    'dana_gateway' => [
        'environment' => env('DANA_GATEWAY_ENVIRONMENT', 'sandbox'),
        'base_urls' => [
            'sandbox' => env('DANA_GATEWAY_SANDBOX_BASE_URL'),
            'production' => env('DANA_GATEWAY_PRODUCTION_BASE_URL'),
        ],
        'timeout' => (int) env('DANA_GATEWAY_TIMEOUT', 8),
        'connect_timeout' => (int) env('DANA_GATEWAY_CONNECT_TIMEOUT', 3),
        'merchant_api_keys' => json_decode((string) env('DANA_GATEWAY_MERCHANT_API_KEYS', '{}'), true) ?: [],
        'merchants' => json_decode((string) env('DANA_GATEWAY_MERCHANTS', '{}'), true) ?: [],
    ],

    'dana_reconciliation' => [
        'max_attempts' => (int) env('DANA_RECONCILIATION_MAX_ATTEMPTS', 5),
        'batch_limit' => (int) env('DANA_RECONCILIATION_BATCH_LIMIT', 25),
        'base_backoff_seconds' => (int) env('DANA_RECONCILIATION_BASE_BACKOFF_SECONDS', 15),
        'max_backoff_seconds' => (int) env('DANA_RECONCILIATION_MAX_BACKOFF_SECONDS', 900),
        'processing_timeout_seconds' => (int) env('DANA_RECONCILIATION_PROCESSING_TIMEOUT_SECONDS', 120),
    ],

    'dana_merchant_provisioning' => [
        'base_url' => env('DANA_MERCHANT_PROVISIONING_BASE_URL'),
        'service_token' => env('DANA_MERCHANT_PROVISIONING_SERVICE_TOKEN'),
        'timeout' => (int) env('DANA_MERCHANT_PROVISIONING_TIMEOUT', 8),
        'connect_timeout' => (int) env('DANA_MERCHANT_PROVISIONING_CONNECT_TIMEOUT', 3),
    ],

    'dana_merchant_secret_store' => [
        'active_key_version' => env('DANA_MERCHANT_SECRET_STORE_ACTIVE_KEY_VERSION'),
        'keys' => json_decode((string) env('DANA_MERCHANT_SECRET_STORE_KEYS', '{}'), true) ?: [],
    ],

];
