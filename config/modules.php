<?php

declare(strict_types=1);

use Modules\Core\Public\Services\UserDataPathService;
use Nwidart\Modules\Activators\FileActivator;
use Nwidart\Modules\Providers\ConsoleServiceProvider;

return [

    /*
    |--------------------------------------------------------------------------
    | Module Namespace
    |--------------------------------------------------------------------------
    */

    'namespace' => 'Modules',

    /*
    |--------------------------------------------------------------------------
    | Module Stubs (unused — we author module providers by hand)
    |--------------------------------------------------------------------------
    */

    'stubs' => [
        'enabled' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Module Paths
    |--------------------------------------------------------------------------
    */

    'paths' => [
        'modules' => UserDataPathService::modulesPath(),
        'assets' => UserDataPathService::publicPath('modules'),
        'migration' => UserDataPathService::migrationsPath(),
        'app_folder' => '',
        'generator' => [
            'public' => ['path' => 'Public', 'generate' => true],
            'internal' => ['path' => 'Internal', 'generate' => true],
            'config' => ['path' => 'config', 'generate' => false],
            'command' => ['path' => 'Console', 'generate' => false],
            'migration' => ['path' => 'Database/Migrations', 'generate' => true],
            'seeder' => ['path' => 'Database/Seeders', 'generate' => true],
            'factory' => ['path' => 'Database/Factories', 'generate' => true],
            'model' => ['path' => 'Models', 'generate' => true],
            'routes' => ['path' => 'Routes', 'generate' => true],
            'controller' => ['path' => 'Http/Controllers', 'generate' => true],
            'middleware' => ['path' => 'Http/Middleware', 'generate' => true],
            'livewire' => ['path' => 'Http/Livewire', 'generate' => true],
            'provider' => ['path' => 'Providers', 'generate' => true],
            'views' => ['path' => 'Resources/views', 'generate' => true],
            'lang' => ['path' => 'Resources/lang', 'generate' => true],
            'test-unit' => ['path' => 'tests/Unit', 'generate' => true],
            'test-feature' => ['path' => 'tests/Feature', 'generate' => true],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Package Commands
    |--------------------------------------------------------------------------
    */

    'commands' => ConsoleServiceProvider::defaultCommands()->toArray(),

    /*
    |--------------------------------------------------------------------------
    | Scan Path
    |--------------------------------------------------------------------------
    */

    'scan' => [
        'enabled' => false,
        'paths' => [
            UserDataPathService::projectPath('vendor/*/*'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Composer File Template
    |--------------------------------------------------------------------------
    */

    'composer' => [
        'vendor' => 'beatrax',
        'author' => [
            'name' => 'beatrax',
            'email' => 'noreply@beatrax.test',
        ],
        'composer-output' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Caching
    |--------------------------------------------------------------------------
    */

    'cache' => [
        'enabled' => false,
        'driver' => 'file',
        'key' => 'beatrax-modules',
        'lifetime' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Choose what laravel-modules will register as custom namespaces.
    |--------------------------------------------------------------------------
    */

    'register' => [
        'translations' => true,
        'files' => 'register',
    ],

    /*
    |--------------------------------------------------------------------------
    | Activators
    |--------------------------------------------------------------------------
    */

    'activators' => [
        'file' => [
            'class' => FileActivator::class,
            'statuses-file' => UserDataPathService::projectPath('modules_statuses.json'),
            'cache-key' => 'beatrax-activator',
            'cache-lifetime' => 604800,
        ],
    ],

    'activator' => 'file',

];
