<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

use Illuminate\Support\ServiceProvider;
use ReflectionClass;

// Loads a module's migrations, web routes, views, and translations from the
// conventional Modules/<X>/{Database,Routes,Resources} layout, each guarded so a
// module missing one skips it. Replaces the loader block the module providers
// hand-rolled; console routes and non-standard route loading stay explicit.
/**
 * @phpstan-require-extends ServiceProvider
 */
trait LoadsModuleResources
{
    protected function loadModuleResources(string $namespace): void
    {
        $providerFile = new ReflectionClass(static::class)->getFileName();
        if ($providerFile === false) {
            return;
        }
        $base = dirname($providerFile).'/..';

        if (is_dir($base.'/Database/Migrations')) {
            $this->loadMigrationsFrom($base.'/Database/Migrations');
        }
        if (is_file($base.'/Routes/web.php')) {
            $this->loadRoutesFrom($base.'/Routes/web.php');
        }
        if (is_dir($base.'/Resources/views')) {
            $this->loadViewsFrom($base.'/Resources/views', $namespace);
        }
        if (is_dir($base.'/Resources/lang')) {
            $this->loadTranslationsFrom($base.'/Resources/lang', $namespace);
        }
    }
}
