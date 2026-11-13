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

    'google' => [
        'id_token_audience' => env('GOOGLE_ID_TOKEN_AUDIENCE'),
    ],

    'openmrs' => [
        'allowed_hosts' => array_filter(array_map('trim', explode(',', (string) env('OPENMRS_ALLOWED_HOSTS', '')))),
        'enforce_https' => (bool) env('OPENMRS_ENFORCE_HTTPS', false),
        'allow_local_network' => env('OPENMRS_ALLOW_LOCAL') !== null ? (bool) env('OPENMRS_ALLOW_LOCAL') : null,
        'strict_ssrf' => (bool) env('OPENMRS_STRICT_SSRF', false),
    ],

];
