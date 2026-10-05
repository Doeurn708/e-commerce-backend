<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | The Vue dev server runs on http://localhost:5173 while the API runs on
    | http://127.0.0.1:8000. Those are different origins, so Laravel has to
    | explicitly allow the SPA. Origins are listed one by one instead of using
    | a wildcard so no other site can talk to this API.
    |
    | `CORS_ALLOWED_ORIGINS` is a comma separated list. It is deliberately kept
    | separate from `FRONTEND_URL`, which must stay a single URL because
    | GoogleAuthController uses it to build the OAuth callback redirect.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173,http://127.0.0.1:5173'))
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    // The SPA authenticates with a Sanctum bearer token in the Authorization
    // header, so cookies are not used. Keeping this false avoids the
    // "wildcard origin + credentials" browser restriction.
    'supports_credentials' => false,

    'max_age' => 3600,

];
