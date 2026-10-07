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

    'whatsapp' => [
        // Meta Graph API version used for WhatsApp Cloud API calls.
        'api_version' => env('WHATSAPP_API_VERSION', 'v21.0'),
        'base_url' => env('WHATSAPP_BASE_URL', 'https://graph.facebook.com'),
    ],

    // WA Hub: sends WhatsApp messages from each branch's own linked number.
    // Server to server only (HMAC signed); without key + secret the feature
    // behaves as unavailable and no HTTP call is made.
    'wahub' => [
        'url' => env('WAHUB_URL', 'https://wa.bookflow.tech'),
        'key' => env('WAHUB_KEY'),
        'secret' => env('WAHUB_SECRET'),
        'timeout' => (int) env('WAHUB_TIMEOUT', 8),
        // {{login_link}} for a branch without its own domain.
        'login_link' => env('WAHUB_LOGIN_LINK', 'https://southindiaexch.com'),
    ],

];
