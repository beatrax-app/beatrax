<?php

declare(strict_types=1);

use Modules\Core\Public\Services\UserDataPathService;

return [

    /*
    |--------------------------------------------------------------------------
    | View Storage Paths
    |--------------------------------------------------------------------------
    |
    | Most templating systems load templates from disk. Here you may specify
    | an array of paths that should be checked for your views. Of course
    | the usual Laravel view path has already been registered for you.
    |
    */

    'paths' => [
        resource_path('views'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Compiled View Path
    |--------------------------------------------------------------------------
    |
    | This overrides the framework default of
    | `realpath(storage_path('framework/views'))`. `realpath()` returns
    | `false` for a directory that does not exist yet, which used to freeze
    | `view.compiled` at an empty value on the NativePHP mobile runtime: the
    | native app-copy rsync strips `storage/framework/*` on every
    | install/update, so on a genuine COLD boot THIS config file (loaded
    | during config-load, before the container exists) could resolve before
    | `mobile-app/bootstrap/app.php`'s `->booted()` hook has a chance to
    | recreate the dir. An empty `view.compiled` makes Blade's Compiler
    | constructor throw "Please provide a valid cache path." on every
    | render — a one-time 500 on the FIRST cold boot that self-healed on the
    | next (warm) boot once the hook had run.
    |
    | Dropping `realpath()` removes the boot-order dependency entirely:
    | `UserDataPathService::frameworkPath('views')` always returns a stable,
    | non-empty absolute path (respecting `NATIVEPHP_STORAGE_PATH`, the same
    | user-data storage root every other path in the app resolves through)
    | whether or not the directory exists, and Blade's compiler
    | auto-creates the compiled-view directory itself on first compile
    | (`Compiler::ensureCompiledDirectoryExists()`), so a not-yet-existing
    | target here is harmless. This is a no-op behavior change for
    | desktop/host, where the directory already exists at boot time. The
    | `->booted()` reconcile hook still recreates the stripped
    | `storage/framework/*` tree on mobile too (belt-and-suspenders, and it
    | also reconciles session/cache to the `database` driver), but
    | `view.compiled` no longer depends on that hook's timing.
    |
    */

    'compiled' => env('VIEW_COMPILED_PATH', UserDataPathService::frameworkPath('views')),

];
