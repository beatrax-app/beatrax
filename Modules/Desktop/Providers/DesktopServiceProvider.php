<?php

declare(strict_types=1);

namespace Modules\Desktop\Providers;

use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Auth\Public\Contracts\ColdStartVault;
use Modules\Auth\Public\Contracts\KeyCustodian;
use Modules\Auth\Public\Events\AppLockPassphraseChanged;
use Modules\Core\Public\Contracts\SecretShield;
use Modules\Core\Public\Events\UpdateInstallRequested;
use Modules\Core\Public\Services\HostPipeWatch;
use Modules\Core\Public\Support\LoadsModuleResources;
use Modules\Desktop\Internal\Http\Livewire\CloseWindowPrompt;
use Modules\Desktop\Internal\Http\Livewire\FileStagingPage;
use Modules\Desktop\Internal\Http\Livewire\SetupScreen;
use Modules\Desktop\Internal\Http\Livewire\WelcomeScreen;
use Modules\Desktop\Internal\Listeners\ApplyCloseWindowChoice;
use Modules\Desktop\Internal\Listeners\ContinuePendingFileIntentAfterLogin;
use Modules\Desktop\Internal\Listeners\DispatchOsNotification;
use Modules\Desktop\Internal\Listeners\ForgetColdStartVaultOnKeyRotation;
use Modules\Desktop\Internal\Listeners\HandleNativeOpenFile;
use Modules\Desktop\Internal\Listeners\LockOnWindowHideOrClose;
use Modules\Desktop\Internal\Listeners\NavigateOnNotificationDeepLink;
use Modules\Desktop\Internal\Listeners\StartSyncListenerOnEnable;
use Modules\Desktop\Internal\Listeners\SurfaceWorkerCrashAlert;
use Modules\Desktop\Internal\Listeners\TriggerUpdateDownload;
use Modules\Desktop\Internal\Listeners\VerifyAndAnnounceUpdate;
use Modules\Desktop\Internal\Listeners\VerifyAndInstallDownload;
use Modules\Desktop\Internal\Native\AppMenuBuilder;
use Modules\Desktop\Internal\Native\DesktopColdStartVault;
use Modules\Desktop\Internal\Native\DesktopKeyCustodian;
use Modules\Desktop\Internal\Native\NativeBiometricUnlock;
use Modules\Desktop\Internal\Native\OsThemeProbe;
use Modules\Desktop\Internal\Native\PendingFileIntent;
use Modules\Desktop\Internal\Native\SafeStorageSecretShield;
use Modules\Desktop\Internal\Native\WindowCloseBehavior;
use Modules\Desktop\Internal\Native\WindowFocusState;
use Modules\Desktop\Public\Contracts\OsThemeSignal;
use Modules\Desktop\Public\Contracts\RemembersPendingFileIntent;
use Modules\Desktop\Public\Events\NotificationDeepLink;
use Modules\Notifications\Public\Events\NotificationDeliverable;
use Modules\Sync\Public\Events\DeviceSyncEnabled;
use Modules\Sync\Public\Events\SyncTransportCredentialsAvailable;
use Native\Desktop\Events\App\OpenFile;
use Native\Desktop\Events\AutoUpdater\UpdateAvailable;
use Native\Desktop\Events\AutoUpdater\UpdateDownloaded;
use Native\Desktop\Events\ChildProcess\ProcessExited;
use Native\Desktop\Events\Windows\WindowBlurred;
use Native\Desktop\Events\Windows\WindowClosed;
use Native\Desktop\Events\Windows\WindowFocused;
use Native\Desktop\Events\Windows\WindowHidden;

/**
 * @link ../../../.docs/features/desktop/architecture.md
 */
final class DesktopServiceProvider extends ServiceProvider
{
    use LoadsModuleResources;

    public function register(): void
    {
        $this->app->singleton(AppMenuBuilder::class);
        $this->app->singleton(WindowFocusState::class);
        $this->app->singleton(DispatchOsNotification::class);

        // Singleton because the rolling crash-counter state lives on
        // the listener — it must survive across every ProcessExited
        // event.
        $this->app->singleton(SurfaceWorkerCrashAlert::class);

        $this->app->singleton(WindowCloseBehavior::class);

        $this->app->singleton(PendingFileIntent::class);
        $this->app->bind(RemembersPendingFileIntent::class, PendingFileIntent::class);

        $this->app->singleton(ContinuePendingFileIntentAfterLogin::class);
        $this->app->singleton(HandleNativeOpenFile::class);
        $this->app->singleton(NavigateOnNotificationDeepLink::class);
        $this->app->singleton(ApplyCloseWindowChoice::class);

        // Unused by design, not by omission: Touch ID returns only a bool,
        // and releasing the data key from it needs a persisted wrapped-KEK
        // vault like the mobile cold-start one. Wiring the prompt without
        // that vault would unlock the UI while the key stayed sealed.
        $this->app->singleton(NativeBiometricUnlock::class);

        // Concrete registration; the KeyCustodian contract is pointed at it
        // inside the NativePHP bundle only (see the binding below). Outside
        // the bundle the unlocked key follows session custody.
        $this->app->singleton(DesktopKeyCustodian::class);

        // OsThemeProbe binds to OsThemeSignal ONLY inside the
        // NativePHP bundle. Under local dev / CI / before Electron is
        // ready, no binding is registered — the app-layout's bound()
        // check falls through to the client-side pre-paint script.
        $config = $this->app->make(ConfigRepository::class);
        if ($config->get('nativephp-internal.running') === true) {
            $this->app->singleton(OsThemeSignal::class, OsThemeProbe::class);

            // Route the unlocked data key through Electron safeStorage
            // instead of the plaintext session copy. Web/CI never
            // enter this block, so they keep the pass-through session
            // custody.
            $this->app->singleton(KeyCustodian::class, DesktopKeyCustodian::class);

            // Touch ID unlock: the vault wraps the data key and hands it to
            // safeStorage, so the prompt releases a real key instead of only
            // reporting that the user authenticated.
            $this->app->singleton(ColdStartVault::class, DesktopColdStartVault::class);

            // At-rest keychain shielding for persisted secrets
            // (biometric wrap blob, OAuth token blob), delegating to
            // DesktopKeyCustodian so safeStorage stays in one place.
            $this->app->singleton(SecretShield::class, SafeStorageSecretShield::class);

            $this->reassertNativePhpSecret($config);
        }
    }

    // NativePHP mints a fresh secret every launch; a stale Windows
    // config:cache can strand a prior launch's value while the live
    // window sends the current one, 403ing every request. getenv()
    // reads the live process environment, so this is always correct.
    private function reassertNativePhpSecret(ConfigRepository $config): void
    {
        $secret = getenv('NATIVEPHP_SECRET');

        if (is_string($secret) && $secret !== '') {
            $config->set('nativephp-internal.secret', $secret);
        }
    }

    public function boot(LivewireManager $livewire, Dispatcher $events): void
    {
        $this->loadModuleResources('desktop');

        $livewire->component('desktop.setup-screen', SetupScreen::class);
        $livewire->component('desktop.welcome-screen', WelcomeScreen::class);
        $livewire->component('desktop.close-window-prompt', CloseWindowPrompt::class);
        $livewire->component('desktop.file-staging-page', FileStagingPage::class);

        // Registration is NOT bundle-gated: it must light up in the
        // spawned queue:work process (which never sets
        // nativephp-internal.running), so it precedes the bundle-only
        // return guard below.
        $queueManager = $this->app->make(QueueManager::class);
        $queueManager->looping(static function (): void {
            // PHP's max_execution_time is wall-clock on Windows (no
            // ext-pcntl, so Laravel's per-job timeout never arms);
            // resetting per poll defuses the 120s ceiling accruing
            // across the long-lived daemon.
            @set_time_limit(0);

            // A force quit never runs the supervisor's before-quit hook, so
            // this worker was left running for hours against a dead app —
            // spawning a job process every few seconds the whole time.
            // Between polls is the one place a blocking worker can notice.
            if (HostPipeWatch::hostHasGone()) {
                exit(0);
            }
        });

        // Subscription is NOT bundle-gated: the pending-intent
        // round-trip must work in local dev / CI / tests too, and the
        // listener touches only the Session contract, no facade.
        $events->listen(Login::class, [ContinuePendingFileIntentAfterLogin::class, 'handle']);

        // Also outside the bundle gate: enabling sync must bring the listener
        // up in the same session, and startIfEnabled() is a no-op wherever
        // NativePHP's ChildProcess facade is absent.

        // A changed passphrase re-wraps the data key, so anything the vault
        // holds can no longer decrypt — drop it rather than fail an unlock.
        $events->listen(
            AppLockPassphraseChanged::class,
            [ForgetColdStartVaultOnKeyRotation::class, 'handle'],
        );

        $events->listen(DeviceSyncEnabled::class, [StartSyncListenerOnEnable::class, 'handle']);
        $events->listen(
            SyncTransportCredentialsAvailable::class,
            [StartSyncListenerOnEnable::class, 'handleCredentialsAvailable'],
        );

        $config = $this->app->make(ConfigRepository::class);
        if ($config->get('nativephp-internal.running') !== true) {
            return;
        }

        // Focus-state flippers: NativePHP's WindowFocused/WindowBlurred
        // feed the shared WindowFocusState singleton so
        // DispatchOsNotification sees the current state at firing time.
        $focusState = $this->app->make(WindowFocusState::class);
        $events->listen(WindowFocused::class, static function () use ($focusState): void {
            $focusState->markFocused();
        });
        $events->listen(WindowBlurred::class, static function () use ($focusState): void {
            $focusState->markBlurred();
        });

        $events->listen(NotificationDeliverable::class, [DispatchOsNotification::class, 'handleNotificationDeliverable']);
        $events->listen(ProcessExited::class, [SurfaceWorkerCrashAlert::class, 'handle']);
        $events->listen(OpenFile::class, [HandleNativeOpenFile::class, 'handle']);

        // Both WindowHidden and WindowClosed route to the same handler
        // — immediate lock, no grace period.
        $events->listen(WindowHidden::class, [LockOnWindowHideOrClose::class, 'handle']);
        $events->listen(WindowClosed::class, [LockOnWindowHideOrClose::class, 'handle']);

        $events->listen(NotificationDeepLink::class, [NavigateOnNotificationDeepLink::class, 'handle']);

        // The explicit-consent auto-update chain, live only inside the bundle:
        // electron-updater discovers an update but downloads nothing until the
        // first listener verifies the signed manifest and the user consents,
        // and the last listener re-verifies the binary before it installs.
        $events->listen(UpdateAvailable::class, [VerifyAndAnnounceUpdate::class, 'handle']);
        $events->listen(UpdateInstallRequested::class, [TriggerUpdateDownload::class, 'handle']);
        $events->listen(UpdateDownloaded::class, [VerifyAndInstallDownload::class, 'handle']);
    }
}
