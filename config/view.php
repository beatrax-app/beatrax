<?php

declare(strict_types=1);

use Modules\Core\Public\Services\UserDataPathService;

return [

    'paths' => [
        resource_path('views'),
    ],

    /**
     * @link ../.docs/features/core/durable-user-data-paths.md#why-configviewphp-must-not-call-realpath
     */
    // Not the framework's `realpath(storage_path('framework/views'))`:
    // `realpath()` is `false` for a directory that does not exist yet, which
    // froze `view.compiled` empty on a mobile cold boot and made Blade throw
    // "Please provide a valid cache path." on every render.
    'compiled' => env('VIEW_COMPILED_PATH', UserDataPathService::frameworkPath('views')),

];
