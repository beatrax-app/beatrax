<?php

declare(strict_types=1);

namespace Modules\Mobile\Providers;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Livewire\Facades\GenerateSignedUploadUrlFacade;
use Livewire\LivewireManager;
use Modules\Auth\Public\Contracts\ColdStartVault;
use Modules\Auth\Public\Contracts\KeyCustodian;
use Modules\Auth\Public\Events\AppLockPassphraseChanged;
use Modules\Core\Public\Contracts\DeviceNameSource;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Core\Public\Support\LoadsModuleResources;
use Modules\Mobile\Commands\CheckPermissionsCommand;
use Modules\Mobile\Commands\InspectBundleCommand;
use Modules\Mobile\Commands\MobilePullCommand;
use Modules\Mobile\Commands\PackageAndroidCommand;
use Modules\Mobile\Internal\Boot\IosSigningPreflight;
use Modules\Mobile\Internal\Boot\MobileFirstLaunchBootstrap;
use Modules\Mobile\Internal\Boot\NativeBuildPatches;
use Modules\Mobile\Internal\Http\BridgeSignedUploadUrl;
use Modules\Mobile\Internal\Http\Livewire\ColdStartBiometricSettingsSection;
use Modules\Mobile\Internal\Http\Livewire\MobileImportBootstrap;
use Modules\Mobile\Internal\Http\Livewire\MobileLockScreen;
use Modules\Mobile\Internal\Http\Livewire\MobileNotificationPermission;
use Modules\Mobile\Internal\Http\Livewire\MobilePairingScan;
use Modules\Mobile\Internal\Http\Livewire\MobileWelcomeScreen;
use Modules\Mobile\Internal\Http\Livewire\SchemaIncompleteScreen;
use Modules\Mobile\Internal\Http\Livewire\SetupProgressScreen;
use Modules\Mobile\Internal\Http\Livewire\SyncCompleteScreen;
use Modules\Mobile\Internal\Http\Livewire\SyncScreen;
use Modules\Mobile\Internal\Http\Middleware\ClientSideRedirect;
use Modules\Mobile\Internal\Http\Middleware\EncodedUploadTransport;
use Modules\Mobile\Internal\Identity\BiometricKeyVault;
use Modules\Mobile\Internal\Identity\BiometricUnlockBridge;
use Modules\Mobile\Internal\Identity\ClearColdStartVaultOnKeyRotation;
use Modules\Mobile\Internal\Identity\MobileColdStartVault;
use Modules\Mobile\Internal\Identity\SecureStorageKeyCustodian;
use Modules\Mobile\Internal\Native\NativeDeviceName;
use Modules\Mobile\Internal\Notifications\NativeNotificationConsent;
use Modules\Mobile\Internal\Notifications\NativeNotificationGrantState;
use Modules\Mobile\Internal\Sync\NetworkPolicyResolver;
use Modules\Notifications\Public\Contracts\SystemNotificationConsent;
use Modules\Notifications\Public\Contracts\SystemNotificationGrantState;

final class MobileServiceProvider extends ServiceProvider
{
    use LoadsModuleResources;

    public function register(): void
    {
        // A booting callback, not boot(): iOS reads the background-task
        // manifest in BackgroundTasksServiceProvider::boot(), which runs long
        // before this provider's, and a schedule declared there arrives too
        // late to reach it — the phone scheduled nothing at all.

        // Not register() either. Schedule resolves the cache for its overlap
        // mutex, and the cache is not bindable yet while providers register.
        // These callbacks run once registration is complete and before the
        // first provider boots, which is the only window that satisfies both.

        // require_once rather than loadRoutesFrom(): a console route is not
        // part of the route cache, so that helper would drop this file
        // entirely the moment routes are cached.
        $this->app->booting(static function (): void {
            if (is_file(__DIR__.'/../Routes/console.php')) {
                require_once __DIR__.'/../Routes/console.php';
            }
        });

        // Stateless (wraps the console Kernel migrator), safe as a
        // singleton.
        $this->app->singleton(MobileFirstLaunchBootstrap::class);

        $this->app->singleton(NetworkPolicyResolver::class);

        $this->app->singleton(BiometricUnlockBridge::class);
        $this->app->singleton(BiometricKeyVault::class);

        // Also registered in boot() via $this->commands([...]). Built
        // explicitly rather than autowired so the session is passed as a
        // factory: it is configured encrypted, and Artisan constructs every
        // command just to list them.
        $this->app->singleton(MobilePullCommand::class);

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

        // Two facts only the device itself knows: its name, and whether the
        // OS is in dark mode. Both have shell-wide fallbacks that are simply
        // wrong on a phone — "Linux" as a device name, and a light first
        // paint on a dark device.
        if (class_exists('Native\Mobile\Facades\Device') && UserDataPathService::isMobileRuntime()) {
            $this->app->singleton(DeviceNameSource::class, NativeDeviceName::class);
        }

        // NO OsThemeSignal binding on mobile, deliberately. The bridge is read
        // per request and can answer differently while the app is idle or
        // paused — exactly when the idle lock renders the PIN screen.

        // The pre-paint script reads prefers-color-scheme instead: the same OS
        // night-mode flag the native window background uses, so every layer
        // agrees at all times.

        // Android 13 and later drop every notification until the reader has
        // granted POST_NOTIFICATIONS, and the grant only comes from a prompt
        // the app raises. Measured on a Samsung: granted=false with no
        // USER_SET flag, and the package at importance=NONE.
        if (class_exists('NativePHP\\LocalNotifications\\Facades\\LocalNotifications') && UserDataPathService::isMobileRuntime()) {
            $this->app->singleton(SystemNotificationConsent::class, NativeNotificationConsent::class);

            // The read half. Bound here rather than unconditionally because
            // the record it reads is written by the device prompt, and a root
            // that never raises one would report NeverAsked forever.
            $this->app->singleton(SystemNotificationGrantState::class, NativeNotificationGrantState::class);
        }

        // The enclave-gated key vault, presented through the shared contract
        // so the lock screen asks one question on every platform.
        if (class_exists('Beatrax\BiometricVault\Facades\BiometricVault') && UserDataPathService::isMobileRuntime()) {
            $this->app->singleton(ColdStartVault::class, MobileColdStartVault::class);
        }
    }

    public function boot(Dispatcher $events, Router $router, ViewFactory $views): void
    {
        // Cold-start biometric: invalidate the enclave blob if the app-lock
        // data key ever rotates (oldKek !== newKek).
        $events->listen(AppLockPassphraseChanged::class, [ClearColdStartVaultOnKeyRotation::class, 'handle']);

        // Re-apply the generated-project patches on every mobile build, not
        // only on composer install: the build tooling regenerates the Android
        // project, which would otherwise ship without them. native:package is
        // the release path, and omitting it kept the patches out of every APK.
        $events->listen(CommandStarting::class, function (CommandStarting $event): void {
            if (! in_array($event->command, ['native:run', 'native:build', 'native:package'], true)) {
                return;
            }

            $team = $this->app->make(ConfigRepository::class)->get('nativephp.development_team');

            $warning = IosSigningPreflight::teamIdWarning(is_string($team) ? $team : null);

            if ($warning !== null) {
                $event->output->writeln('<comment>'.$warning.'</comment>');
            }

            $scripts = NativeBuildPatches::locate($this->app->basePath());

            if ($scripts !== null) {
                $this->app->make(NativeBuildPatches::class)->apply($scripts);
            }
        });

        $this->loadModuleResources('mobile');

        // The mobile-only Livewire full-page screens, wired to the tags
        // Routes/web.php references by string.
        /** @var LivewireManager $livewire */
        $livewire = $this->app->make(LivewireManager::class);

        $livewire->component('mobile.import-bootstrap', MobileImportBootstrap::class);
        $livewire->component('mobile.lock-screen', MobileLockScreen::class);
        $livewire->component('mobile.notification-permission', MobileNotificationPermission::class);
        $livewire->component('mobile.pairing-scan', MobilePairingScan::class);
        $livewire->component('mobile.setup-progress-screen', SetupProgressScreen::class);
        $livewire->component('mobile.sync-complete-screen', SyncCompleteScreen::class);
        $livewire->component('mobile.sync-screen', SyncScreen::class);
        $livewire->component('mobile.schema-incomplete-screen', SchemaIncompleteScreen::class);
        $livewire->component('mobile.welcome-screen', MobileWelcomeScreen::class);
        $livewire->component('mobile.cold-start-biometric-settings-section', ColdStartBiometricSettingsSection::class);

        $this->commands([
            MobilePullCommand::class,
            PackageAndroidCommand::class,
            InspectBundleCommand::class,
            CheckPermissionsCommand::class,
        ]);

        // Where the runtime cannot carry a multipart body, the client sends the
        // file base64-encoded and this puts a real UploadedFile back before
        // Livewire's controller reads one. Gated on its own marker, not the
        // platform: an ordinary request is one array lookup and out.
        $router->pushMiddlewareToGroup('web', EncodedUploadTransport::class);

        // Same reasoning as above: pushed everywhere, gated on the runtime
        // inside, so a desktop response is one isRedirection() check and out.
        $router->pushMiddlewareToGroup('web', ClientSideRedirect::class);

        // Gated on the runtime, not merely on this provider loading: modules
        // are auto-discovered, so this boots in the DESKTOP root too, and an
        // ungated share would move every desktop upload onto the encoded path.
        // The middleware is inert without the marker; the flag is not.
        if (UserDataPathService::isMobileRuntime()) {
            $views->share('beatraxEncodedUploads', true);
        }

        // Livewire mints its temporary-upload URL through this facade, the one
        // seam where the signature can be computed against the root the verifier
        // rebuilds. swap() after boot, not $app->instance(): it evicts the
        // facade's cached root, which Livewire fills with a stub under tests.
        $this->app->booted(function (): void {
            GenerateSignedUploadUrlFacade::swap($this->app->make(BridgeSignedUploadUrl::class));
        });
    }
}
