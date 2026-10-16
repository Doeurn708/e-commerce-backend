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

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),

        // Must match, byte for byte, a URI registered in Google Cloud Console.
        // GOOGLE_REDIRECT_URI wins when set (an empty value is treated as unset
        // so a blank Railway variable cannot break the flow); otherwise the
        // callback is derived from the app URL - config('app.url') already
        // falls back to the Railway public domain, so production answers
        // https://store-padon708.up.railway.app/api/auth/google/callback and
        // local answers http://127.0.0.1:8000/api/auth/google/callback. The path
        // mirrors the `api/auth/google/callback` route declared in routes/api.php.
        'redirect' => env('GOOGLE_REDIRECT_URI')
            ?: rtrim((string) config('app.url'), '/').'/api/auth/google/callback',

        // Single SPA origin (never a list): GoogleAuthController appends
        // /auth/google/callback to it when it builds the post-login redirect.
        'frontend_url' => env('FRONTEND_URL') ?: 'http://localhost:5173',
    ],
];
