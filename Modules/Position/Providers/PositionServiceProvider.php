<?php

declare(strict_types=1);

namespace Modules\Position\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Core\Public\Support\LoadsModuleResources;
use Modules\Position\Public\Services\PositionQuery;

final class PositionServiceProvider extends ServiceProvider
{
    use LoadsModuleResources;

    public function register(): void
    {
        $this->app->singleton(PositionQuery::class);
    }

    public function boot(): void
    {
        $this->loadModuleResources('position');
    }
}
