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

    // v0.3.5 Payment Gateway — sandbox only this sprint (BOSS-005: secrets
    // live in .env, never hardcoded/committed). is_production is a safety
    // guard read at request-time by XenditGatewayService (see its
    // guardEnvironmentMatchesConfiguredMode()) — never reuse a sandbox key
    // expecting it to work in production mode or vice versa.
    //
    // Fase H (v0.3.5): secret_key/callback_token below are ONLY read once
    // more, by `php artisan payment-gateway:import-env` — the real runtime
    // source for both is now payment_gateway_settings (encrypted DB row),
    // see PaymentGatewaySettingsService. Do not add new config()/env() reads
    // of these two keys elsewhere.
    'xendit' => [
        'secret_key' => env('XENDIT_SECRET_KEY'),
        'callback_token' => env('XENDIT_CALLBACK_TOKEN'),
        'is_production' => env('XENDIT_IS_PRODUCTION', false),
    ],

];
