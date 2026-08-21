<?php

declare(strict_types=1);

namespace App\Providers;

use Beatrax\BiometricVault\BiometricVaultServiceProvider;
use Illuminate\Support\ServiceProvider;
use Native\Mobile\Providers\BiometricsServiceProvider;
use Native\Mobile\Providers\NetworkServiceProvider;
use Native\Mobile\Providers\ScannerServiceProvider;
use Native\Mobile\Providers\SecureStorageServiceProvider;
use Native\Mobile\UI\NativeUIServiceProvider;
use NativePHP\BackgroundTasks\BackgroundTasksServiceProvider;
use NativePHP\LocalNotifications\LocalNotificationsServiceProvider;

// Only providers listed in plugins() compile into a native build, and only
// mobile-app/bootstrap/providers.php loads this; the desktop build has
// nativephp/desktop's own plugin surface.
class NativeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Nothing to bind: the plugin providers listed in plugins() are
        // registered by the native builder, not from here.
    }

    public function boot(): void
    {
        // Nothing to boot either: this provider's whole contribution is the
        // plugins() list the native build reads at compile time.
    }

    /**
     * @return array<int, class-string<ServiceProvider>>
     */
    public function plugins(): array
    {
        return [
            BiometricsServiceProvider::class,
            ScannerServiceProvider::class,
            BackgroundTasksServiceProvider::class,
            NetworkServiceProvider::class,
            // Backs Mobile\Internal\Identity\SecureStorageKeyCustodian.
            SecureStorageServiceProvider::class,
            // Backs Mobile\Internal\Listeners\DispatchMobileNotification.
            LocalNotificationsServiceProvider::class,
            // Backs Mobile\Internal\Identity\BiometricKeyVault, whose guards
            // run through the facade's class_exists(), so omitting this
            // degrades silently rather than failing.
            BiometricVaultServiceProvider::class,
            // The core registers only layout, content and navigation chrome;
            // button, the inputs, toggle and webview resolve only through this.
            NativeUIServiceProvider::class,
        ];
    }
}
