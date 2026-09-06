<?php

declare(strict_types=1);

return [

    'name' => env('APP_NAME', 'Beatrax'),
    'env' => env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'dev_mode' => (bool) env('BEATRAX_DEV_MODE', false),
    'url' => env('APP_URL', 'http://localhost'),
    'timezone' => env('APP_TIMEZONE', 'UTC'),

    // Whether the value above was chosen by whoever installed this copy, or is
    // only the fallback nothing has replaced yet. InstallTimezone needs to tell
    // those apart and env() cannot: it reads UTC for both. Resolved here so a
    // cached config keeps the answer.
    'timezone_pinned' => env('APP_TIMEZONE'),
    'locale' => env('APP_LOCALE', 'en'),
    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),
    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),
    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

];
