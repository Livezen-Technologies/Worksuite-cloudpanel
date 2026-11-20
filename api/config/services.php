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

    // Staff SSO: "Sign in with WorkSuite Cloud" for panel staff (OneAuth, staff audience). Hidden while unset.
    'wsc_oidc' => [
        'issuer' => env('WSC_OIDC_ISSUER'),
        'client_id' => env('WSC_OIDC_CLIENT_ID'),
        'client_secret' => env('WSC_OIDC_CLIENT_SECRET'),
        'redirect_uri' => env('WSC_OIDC_REDIRECT_URI'),
        // Part 2: the organisation staff accounts are created in (defaults to the oldest super admin's).
        'staff_organization_id' => env('WSC_STAFF_ORGANIZATION_ID'),
    ],

];
