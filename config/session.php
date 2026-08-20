<?php

declare(strict_types=1);

use Modules\Core\Public\Services\UserDataPathService;

return [

    'driver' => env('SESSION_DRIVER', 'database'),

    // 30 days: one human uses the local app daily, and a shorter window would
    // log them out of their own machine for no gain.
    'lifetime' => 60 * 24 * 30,
    'expire_on_close' => false,

    // Flash data, validation errors and search inputs land in the sessions
    // table; encryption keeps them unreadable to anything reading that table
    // from outside the app.
    'encrypt' => true,

    'files' => UserDataPathService::frameworkPath('sessions'),

    'connection' => env('SESSION_CONNECTION'),
    'table' => env('SESSION_TABLE', 'sessions'),

    'store' => env('SESSION_STORE'),

    'lottery' => [2, 100],

    'cookie' => env(
        'SESSION_COOKIE',
        'beatrax_session'
    ),

    'path' => env('SESSION_PATH', '/'),

    'domain' => env('SESSION_DOMAIN'),

    // Off by default because the app serves localhost over plain HTTP.
    'secure' => env('SESSION_SECURE_COOKIE', false),

    'http_only' => env('SESSION_HTTP_ONLY', true),

    'same_site' => env('SESSION_SAME_SITE', 'strict'),

    'partitioned' => env('SESSION_PARTITIONED_COOKIE', false),

];
