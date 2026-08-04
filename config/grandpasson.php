<?php

// Mirrors taskconnect config/grandpasson.php, with StatusConnect-specific scopes.

$baseUrl = rtrim((string) env('GRANDPASSON_BASE_URL', ''), '/');

return [
    'outbound_enabled' => filter_var(env('GRANDPASSON_OUTBOUND_ENABLED', false), FILTER_VALIDATE_BOOL),
    'inbound_enabled' => filter_var(env('GRANDPASSON_INBOUND_ENABLED', false), FILTER_VALIDATE_BOOL),
    'base_url' => $baseUrl,

    'rp_client_id' => env('GRANDPASSON_RP_CLIENT_ID', ''),
    'rp_client_secret' => env('GRANDPASSON_RP_CLIENT_SECRET', ''),
    'redirect_uri' => env('GRANDPASSON_REDIRECT_URI', ''),

    'client_id' => env('GRANDPASSON_CLIENT_ID', ''),
    'client_secret' => env('GRANDPASSON_CLIENT_SECRET', ''),

    'login_url' => env('GRANDPASSON_LOGIN_URL', $baseUrl === '' ? '' : $baseUrl.'/login'),
    'exchange_url' => env('GRANDPASSON_EXCHANGE_URL', $baseUrl === '' ? '' : $baseUrl.'/session/exchange'),
    'token_url' => env('GRANDPASSON_TOKEN_URL', $baseUrl === '' ? '' : $baseUrl.'/oauth/token'),
    'introspect_url' => env('GRANDPASSON_INTROSPECT_URL', $baseUrl === '' ? '' : $baseUrl.'/oauth/introspect'),

    'read_scope' => env('GRANDPASSON_READ_SCOPE', 'status:read'),
    'write_scope' => env('GRANDPASSON_WRITE_SCOPE', 'status:write'),
    'callback_scope' => env('GRANDPASSON_CALLBACK_SCOPE', 'status:callback'),
    'token_refresh_skew_seconds' => (int) env('GRANDPASSON_TOKEN_REFRESH_SKEW_SECONDS', 60),
    'introspection_cache_seconds' => (int) env('GRANDPASSON_INTROSPECTION_CACHE_SECONDS', 30),
    'callback_hmac_secret' => env('SC_CALLBACK_HMAC_SECRET', ''),
    'callback_max_skew_seconds' => (int) env('SC_CALLBACK_MAX_SKEW_SECONDS', 300),
];
