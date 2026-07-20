<?php

declare(strict_types=1);

namespace Beatrax\BiometricVault;

use Illuminate\Support\ServiceProvider;

class BiometricVaultServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(BiometricVault::class, fn (): BiometricVault => new BiometricVault);
    }
}
