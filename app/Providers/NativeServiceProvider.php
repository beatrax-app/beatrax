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

// NativePHP treats plugin registration as an explicit opt-in security
// boundary: only providers listed in plugins() compile into a native
// build. Loaded only from mobile-app/bootstrap/providers.php — the
// desktop build uses nativephp/desktop's own plugin surface instead.
class NativeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // No container bindings: this provider's only surface is plugins(),
        // consumed by mobile-app/bootstrap/providers.php at native build time.
    }

    public function boot(): void
    {
        // Nothing to boot: native plugin registration happens through
        // plugins(), never via the framework's boot lifecycle.
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
            // SecureStorage (nativephp/mobile-secure-storage) — backs
            // Modules\Mobile\Internal\Identity\SecureStorageKeyCustodian.
            SecureStorageServiceProvider::class,
            // LocalNotifications (nativephp/mobile-local-notifications) backs
            // Modules\Mobile\Internal\Listeners\DispatchMobileNotification.
            // Without this entry the plugin's native (Swift/Kotlin) code
            // never compiles into the device build.
            LocalNotificationsServiceProvider::class,
            // BiometricVault (beatrax/mobile-biometric-vault, a first-party
            // path-repo plugin) backs Modules\Mobile\Internal\Identity\
            // BiometricKeyVault. Its guards resolve through the facade's
            // class_exists(), so an unregistered plugin degrades silently.
            BiometricVaultServiceProvider::class,
            // The EDGE component library. nativephp/mobile's core registers
            // only layout, content and navigation chrome — button, the text
            // inputs, toggle and webview are deliberately left for a UI
            // plugin to own, and without this none of them resolve.
            NativeUIServiceProvider::class,
        ];
    }
}
