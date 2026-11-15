<?php

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;
use Laravel\Sanctum\Sanctum;

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    |
    | Requests from the following domains / hosts will receive stateful API
    | authentication cookies. Typically, these should include your local
    | and production domains which access your API via a frontend SPA.
    |
    */

    /*
    | Issue #1532 — the merged list, never an env-only list.
    |
    | The host this deployment is actually served from and the `APP_URL` host
    | are always stateful; `SANCTUM_STATEFUL_DOMAINS` only allowlists EXTRA
    | origins. A deployment on any host/port outside the env list (sad1:8000,
    | a LAN IP, a second port) used to 401 every cookie-authenticated
    | `/api/*` fetch, blanking the map and the Zabbix charts — and because
    | `.env.example` pins the variable, the old `APP_URL` fallback never ran.
    |
    | `Sanctum::currentRequestHost()` is the
    | `__SANCTUM_CURRENT_REQUEST_HOST__` placeholder: `fromFrontend()` replaces
    | it with the incoming request's host, and it only ever matches a
    | Referer/Origin equal to that same host — a cross-site page cannot forge
    | it, and a forged Host gets no victim cookie (CSRF + SameSite=lax stand).
    */

    'stateful' => array_values(array_unique(array_filter(array_merge(
        explode(',', env(
            'SANCTUM_STATEFUL_DOMAINS',
            'h-dashboard.test,localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1'
        )),
        [
            ltrim(Sanctum::currentApplicationUrlWithPort(), ','),
            ltrim(Sanctum::currentRequestHost(), ','),
        ],
    )))),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Guards
    |--------------------------------------------------------------------------
    |
    | This array contains the authentication guards that will be checked when
    | Sanctum is trying to authenticate a request. If none of these guards
    | are able to authenticate the request, Sanctum will use the bearer
    | token that's present on an incoming request for authentication.
    |
    */

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
        'api' => [
            'driver' => 'sanctum',
            'provider' => 'users',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Expiration Minutes
    |--------------------------------------------------------------------------
    |
    | This value controls the number of minutes until an issued token will be
    | considered expired. This will override any values set in the token's
    | "expires_at" attribute, but first-party sessions are not affected.
    |
    */

    'expiration' => (int) env('SANCTUM_TOKEN_EXPIRATION', 1440), // 24 hours

    /*
    |--------------------------------------------------------------------------
    | Token Prefix
    |--------------------------------------------------------------------------
    |
    | Sanctum can prefix new tokens in order to take advantage of numerous
    | security scanning initiatives maintained by open source platforms
    | that notify developers if they commit tokens into repositories.
    |
    | See: https://docs.github.com/en/code-security/secret-scanning/about-secret-scanning
    |
    */

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Middleware
    |--------------------------------------------------------------------------
    |
    | When authenticating your first-party SPA with Sanctum you may need to
    | customize some of the middleware Sanctum uses while processing the
    | request. You may change the middleware listed below as required.
    |
    */

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

];
