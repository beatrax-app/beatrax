<?php

declare(strict_types=1);

namespace Modules\Mobile\Providers;

use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;

/**
 * Single-owner provider for the Mobile module.
 *
 * CRITICAL: This provider (plus `Routes/web.php`, `Routes/console.php`, and
 * the `phpstan.neon` native-facade allow-list) is the ONLY wiring surface
 * every later Phase 15 wave needs. Plans 04-11 create the named Internal/
 * Livewire/Commands classes; this provider automatically wires them via
 * `class_exists()`-guarded blocks the moment they exist. No downstream
 * plan ever edits this file, `Routes/web.php`, `Routes/console.php`, or
 * `phpstan.neon` again — mirrors `Modules\Sync\Providers\SyncServiceProvider`'s
 * own docblock precedent (STATE [11-02]).
 *
 * Service bind inventory (by implementing plan):
 *   Plan 04: MobileFirstLaunchBootstrap (on-device migrate-on-launch, no daemon)
 *   Plan 05: LanSyncClient, NetworkPolicyResolver, MobileSyncTriggerService
 *   Plan 06: BiometricUnlockBridge, MobileLockScreen (Livewire)
 *   Plan 07: QrScanBridge, MobilePairingScan (Livewire)
 *   Plan 08: InitialSyncPuller, SetupProgressScreen (Livewire)
 *   Plan 09: MobilePullCommand (artisan command, `sync:mobile-pull`)
 *   Plan 10: SyncScreen (Livewire)
 *
 * Every future class is referenced by a runtime-built FQCN string (never a
 * `use` import / `::class` literal) so this provider stays PHPStan-clean
 * before each class ships — identical idiom to `SyncServiceProvider`'s
 * `singletonIfExists()` helper, copied verbatim below.
 */
final class MobileServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Plan 04: on-device first-launch migration bootstrap. Stateless
        // (wraps the console Kernel migrator), safe as a singleton.
        $this->singletonIfExists('Modules\Mobile\Internal\Boot\MobileFirstLaunchBootstrap');

        // Plan 05: sync transport + orchestration services.
        $syncNamespace = 'Modules\Mobile\Internal\Sync\\';
        foreach ([
            'LanSyncClient',
            'NetworkPolicyResolver',
            'MobileSyncTriggerService',
            'InitialSyncPuller', // Plan 08
        ] as $syncClass) {
            $this->singletonIfExists($syncNamespace.$syncClass);
        }

        // Plan 06: biometric-unlock native bridge.
        $this->singletonIfExists('Modules\Mobile\Internal\Identity\BiometricUnlockBridge');

        // Plan 07: QR-scan native bridge.
        $this->singletonIfExists('Modules\Mobile\Internal\Pairing\QrScanBridge');

        // Plan 09: mobile background-pull artisan command (also registered
        // in boot() via $this->commands([...]) once it exists).
        $this->singletonIfExists('Modules\Mobile\Commands\MobilePullCommand');
    }

    public function boot(): void
    {
        if (is_dir(__DIR__.'/../Database/Migrations')) {
            $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        }

        if (is_dir(__DIR__.'/../Resources/views')) {
            $this->loadViewsFrom(__DIR__.'/../Resources/views', 'mobile');
        }

        if (is_file(__DIR__.'/../Routes/web.php')) {
            $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
        }

        if (is_file(__DIR__.'/../Routes/console.php')) {
            $this->loadRoutesFrom(__DIR__.'/../Routes/console.php');
        }

        // Plans 06/07/08/10: the four mobile-only Livewire full-page
        // screens. Registered by runtime FQCN so this provider stays
        // PHPStan-clean before each plan ships its component; the moment
        // the class exists it is wired to the Livewire tag Routes/web.php
        // references by string.
        if (class_exists(LivewireManager::class)) {
            /** @var LivewireManager $livewire */
            $livewire = $this->app->make(LivewireManager::class);

            $livewireNamespace = 'Modules\Mobile\Internal\Http\Livewire\\';
            $components = [
                'mobile.lock-screen' => 'MobileLockScreen',
                'mobile.pairing-scan' => 'MobilePairingScan',
                'mobile.setup-progress-screen' => 'SetupProgressScreen',
                'mobile.sync-screen' => 'SyncScreen',
            ];

            foreach ($components as $tag => $class) {
                $this->registerLivewireComponentIfExists($livewire, $tag, $livewireNamespace.$class);
            }
        }

        // Plan 09: mobile background-pull artisan command. class_exists-
        // guarded so the provider stays clean before Plan 09 ships it.
        $pullCommand = 'Modules\Mobile\Commands\MobilePullCommand';
        if (class_exists($pullCommand)) {
            $this->commands([$pullCommand]);
        }
    }

    /**
     * Register a singleton for a class that may not exist yet (forward-
     * looking wave wiring). The class name arrives as a runtime-built
     * string so PHPStan does not fold the class_exists() guard to an
     * impossible type. Copied verbatim from
     * `Modules\Sync\Providers\SyncServiceProvider::singletonIfExists()`.
     */
    private function singletonIfExists(string $class): void
    {
        if (class_exists($class)) {
            $this->app->singleton($class);
        }
    }

    /**
     * Register a Livewire full-page component for a class that may not
     * exist yet. Routed through this separate method (rather than an
     * inline `class_exists()` check in the calling foreach loop) so
     * PHPStan does not narrow `$fqcn` to a literal-string union over the
     * loop's array values and fold the guard to an impossible type.
     */
    private function registerLivewireComponentIfExists(LivewireManager $livewire, string $tag, string $fqcn): void
    {
        if (class_exists($fqcn)) {
            $livewire->component($tag, $fqcn);
        }
    }
}
