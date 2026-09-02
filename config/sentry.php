<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Sentry DSN
    |--------------------------------------------------------------------------
    |
    | The Sentry DSN is the unique identifier for your project. It tells Sentry
    | where to send your errors. You can find it in your Sentry project settings.
    |
    | https://docs.sentry.io/product/sentry-basics/dsn-explainer/
    |
    */

    'dsn' => env('SENTRY_LARAVEL_DSN'),

    /*
    |--------------------------------------------------------------------------
    | Sentry Environment
    |--------------------------------------------------------------------------
    |
    | This setting overrides the default release tag name used by Sentry.
    | When set to null, the default app name (APP_NAME) will be used.
    |
    */

    'environment' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Error & Exception Handling
    |--------------------------------------------------------------------------
    |
    | This option determines if the exceptions should be reported to Sentry
    | or not. By default, all exceptions are reported.
    |
    */

    'error_types' => E_ALL & ~E_DEPRECATED & ~E_STRICT,

    /*
    |--------------------------------------------------------------------------
    | Breadcrumbs Logger
    |--------------------------------------------------------------------------
    |
    | Breadcrumbs capture the trail of events that happened prior to an error.
    | You can disable this here to disable specific breadcrumb loggers.
    |
    */

    'breadcrumbs' => [
        'logs' => true,
        'livewire' => true,
        'queue' => true,
        'artisan' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Transaction Sample Rate
    |--------------------------------------------------------------------------
    |
    | A number between 0.0 and 1.0, where 1.0 represents 100% of transactions
    | being sent to Sentry. Adjust this to sample at a lower rate in production.
    |
    */

    'traces_sample_rate' => (float) env('SENTRY_TRACES_SAMPLE_RATE', 0.2),

    /*
    |--------------------------------------------------------------------------
    | Profiler Sample Rate
    |--------------------------------------------------------------------------
    |
    | A number between 0.0 and 1.0, where 1.0 represents 100% of profiles
    | being sent to Sentry. Requires traces_sample_rate > 0.
    |
    */

    'profiles_sample_rate' => (float) env('SENTRY_PROFILES_SAMPLE_RATE', 0.1),

    /*
    |--------------------------------------------------------------------------
    | Send Default PII
    |--------------------------------------------------------------------------
    |
    | When true, Sentry will try to include the user's IP address and other
    | personally identifiable information. Set to false to avoid sending PII.
    |
    */

    'send_default_pii' => false,

];
