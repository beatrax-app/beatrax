<?php

declare(strict_types=1);

namespace Modules\Mobile\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Auth\Public\Contracts\KeyCustodian;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Core\Public\Support\LoadsModuleResources;
use Modules\Mobile\Commands\MobilePullCommand;
use Modules\Mobile\Internal\Identity\SecureStorageKeyCustodian;

/**
 * @link ../../../.docs/features/mobile/architecture.md
 */
final class MobileServiceProvider extends ServiceProvider
{
    use LoadsModuleResources;

    public function register(): void
    {
        // Stateless (wraps the console Kernel migrator), safe as a
        // singleton.
        $this->singletonIfExists('Modules\Mobile\Internal\Boot\MobileFirstLaunchBootstrap');

        $syncNamespace = 'Modules\Mobile\Internal\Sync\\';
        foreach ([
            'LanSyncClient',
            'NetworkPolicyResolver',
            'MobileSyncTriggerService',
            'InitialSyncPuller',
        ] as $syncClass) {
            $this->singletonIfExists($syncNamespace.$syncClass);
        }

        $this->singletonIfExists('Modules\Mobile\Internal\Identity\BiometricUnlockBridge');
        $this->singletonIfExists('Modules\Mobile\Internal\Identity\BiometricKeyVault');
        $this->singletonIfExists('Modules\Mobile\Internal\Identity\ColdStartEnrollmentService');
        $this->singletonIfExists('Modules\Mobile\Internal\Pairing\QrScanBridge');

        // Also registered in boot() via $this->commands([...]) once it
        // exists. Built explicitly rather than autowired so the session is
        // passed as a factory: it is configured encrypted, and Artisan
        // constructs every command just to list them.
        if (class_exists(MobilePullCommand::class)) {
            $this->app->singleton(MobilePullCommand::class);
        }

        // Routes the unlocked data key through the iOS Keychain/Android
        // Keystore instead of the plaintext session copy, overriding the
        // Auth NullKeyCustodian default. The custodian itself re-checks
        // the same guard, so a mis-resolution degrades to pass-through.
        if (class_exists('Native\Mobile\Facades\SecureStorage') && UserDataPathService::isMobileRuntime()) {
            $this->app->singleton(
                KeyCustodian::class,
                SecureStorageKeyCustodian::class,
            );
        }
    }

    public function boot(Dispatcher $events): void
    {
        // Cold-start biometric: invalidate the enclave blob if the
        // app-lock data key ever rotates (oldKek !== newKek). Runtime FQCN
        // strings + class_exists guard keep this provider clean before
        // the class ships.
        $clearListener = 'Modules\Mobile\Internal\Identity\ClearColdStartVaultOnKeyRotation';
        if (class_exists($clearListener)) {
            $events->listen('Modules\Auth\Public\Events\AppLockPassphraseChanged', [$clearListener, 'handle']);
        }

        $this->loadModuleResources('mobile');

        if (is_file(__DIR__.'/../Routes/console.php')) {
            $this->loadRoutesFrom(__DIR__.'/../Routes/console.php');
        }

        // The mobile-only Livewire full-page screens. Registered by
        // runtime FQCN so this provider stays PHPStan-clean before each
        // component ships; the moment the class exists it is wired to
        // the Livewire tag Routes/web.php references by string.
        if (class_exists(LivewireManager::class)) {
            /** @var LivewireManager $livewire */
            $livewire = $this->app->make(LivewireManager::class);

            $livewireNamespace = 'Modules\Mobile\Internal\Http\Livewire\\';
            $components = [
                'mobile.import-bootstrap' => 'MobileImportBootstrap',
                'mobile.lock-screen' => 'MobileLockScreen',
                'mobile.pairing-scan' => 'MobilePairingScan',
                'mobile.setup-progress-screen' => 'SetupProgressScreen',
                'mobile.sync-screen' => 'SyncScreen',
                'mobile.welcome-screen' => 'MobileWelcomeScreen',
                'mobile.cold-start-biometric-settings-section' => 'ColdStartBiometricSettingsSection',
            ];

            foreach ($components as $tag => $class) {
                $this->registerLivewireComponentIfExists($livewire, $tag, $livewireNamespace.$class);
            }
        }

        // class_exists-guarded so the provider stays clean before it
        // ships.
        $pullCommand = 'Modules\Mobile\Commands\MobilePullCommand';
        if (class_exists($pullCommand)) {
            $this->commands([$pullCommand]);
        }
    }

    // Registers a singleton for a class that may not exist yet. The class
    // name arrives as a runtime-built string so PHPStan does not fold the
    // class_exists() guard to an impossible type.
    private function singletonIfExists(string $class): void
    {
        if (class_exists($class)) {
            $this->app->singleton($class);
        }
    }

    // Routed through this separate method (rather than an inline
    // class_exists() check in the calling foreach loop) so PHPStan does
    // not narrow $fqcn to a literal-string union over the loop's array
    // values and fold the guard to an impossible type.
    private function registerLivewireComponentIfExists(LivewireManager $livewire, string $tag, string $fqcn): void
    {
        if (class_exists($fqcn)) {
            $livewire->component($tag, $fqcn);
        }
    }
}
