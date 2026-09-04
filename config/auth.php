<?php

declare(strict_types=1);

use Modules\Auth\Internal\Lock\PwhashLimits;
use Modules\Core\Models\User;

return [

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => User::class,
        ],
    ],

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => (int) env('AUTH_PASSWORD_TIMEOUT', 10800),

    'app_lock' => [
        // The Argon2id cost the app-lock PIN is hashed and its wrap key
        // derived at. The moderate tier (~256 MB, ~500ms a derivation) is
        // what ships, and it is a default here rather than a line in
        // .env.example because the release build copies that template and an
        // empty value there would override a good default. PwhashLimits reads
        // anything that is not the literal reduced tier as the moderate one,
        // so only an environment naming the weaker tier gets it — phpunit.xml
        // does, to keep the suite out of sodium.
        'kdf_tier' => env('APP_LOCK_KDF_TIER', PwhashLimits::PRODUCTION_TIER),
    ],

];
