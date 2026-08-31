<?php

declare(strict_types=1);

namespace Modules\Position\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Core\Public\Support\LoadsModuleResources;

final class PositionServiceProvider extends ServiceProvider
{
    use LoadsModuleResources;

    // Nothing to bind: this module owns no services the container has to be
    // told about, and its views and translations load in boot() like the rest.
    public function register(): void {}

    public function boot(): void
    {
        $this->loadModuleResources('position');
    }
}
