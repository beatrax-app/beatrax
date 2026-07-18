<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Native\Mobile\Providers\BiometricsServiceProvider;
use Native\Mobile\Providers\NetworkServiceProvider;
use Native\Mobile\Providers\ScannerServiceProvider;
use Native\Mobile\Providers\SecureStorageServiceProvider;
use NativePHP\BackgroundTasks\BackgroundTasksServiceProvider;
use NativePHP\LocalNotifications\LocalNotificationsServiceProvider;

/**
 * Registers the NativePHP mobile plugins compiled into the on-device build.
 *
 * NativePHP treats plugin registration as an explicit opt-in security
 * boundary: only providers listed in {@see self::plugins()} are compiled
 * into a native build, so a transitive dependency cannot silently pull in a
 * plugin (and its native permissions) without this file naming it. Registered
 * via `php artisan native:plugin:register` during device UAT (Phase 15) and
 * loaded ONLY from `mobile-app/bootstrap/providers.php` — the desktop root's
 * `bootstrap/providers.php` deliberately does not list this provider, since
 * the desktop build uses `nativephp/desktop`'s own plugin surface instead.
 */
class NativeServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }

    /**
     * The NativePHP plugins to enable.
     *
     * Only plugins listed here will be compiled into your native builds.
     * This is a security measure to prevent transitive dependencies from
     * automatically registering plugins without your explicit consent.
     *
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
            // LocalNotifications (nativephp/mobile-local-notifications, 18-15)
            // — backs Modules\Mobile\Internal\Listeners\DispatchMobileNotification.
            // Without this entry the plugin's native (Swift/Kotlin) code never
            // compiles into the device build, which would silently invalidate
            // Task 3's on-device proof for a wiring reason, not the plugin's
            // genuine v0.0.2 maturity risk.
            LocalNotificationsServiceProvider::class,
        ];
    }
}
