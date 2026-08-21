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

final class DesktopServiceProvider extends ServiceProvider
{
    use LoadsModuleResources;

    public function register(): void
    {
        $this->app->singleton(AppMenuBuilder::class);
        $this->app->singleton(WindowFocusState::class);
        $this->app->singleton(DispatchOsNotification::class);

        // Singleton: the rolling crash-counter state lives on the listener and must
        // survive across every ProcessExited event.
        $this->app->singleton(SurfaceWorkerCrashAlert::class);

        $this->app->singleton(WindowCloseBehavior::class);

        $this->app->singleton(PendingFileIntent::class);
        $this->app->bind(RemembersPendingFileIntent::class, PendingFileIntent::class);

        $this->app->singleton(ContinuePendingFileIntentAfterLogin::class);
        $this->app->singleton(HandleNativeOpenFile::class);
        $this->app->singleton(NavigateOnNotificationDeepLink::class);
        $this->app->singleton(ApplyCloseWindowChoice::class);

        $this->app->singleton(NativeBiometricUnlock::class);

        $this->app->singleton(DesktopKeyCustodian::class);

        // Nothing below is bound outside the bundle, so local dev, CI and the web
        // app keep the pass-through session custody and the app layout's bound()
        // check falls through to its client-side pre-paint theme script.
        $config = $this->app->make(ConfigRepository::class);
        if ($config->get('nativephp-internal.running') === true) {
            $this->app->singleton(OsThemeSignal::class, OsThemeProbe::class);

            // The unlocked data key goes to Electron safeStorage, not a plaintext
            // session copy.
            $this->app->singleton(KeyCustodian::class, DesktopKeyCustodian::class);

            $this->app->singleton(ColdStartVault::class, DesktopColdStartVault::class);

            // Shields the persisted secrets — the biometric wrap blob, OAuth token
            // blobs — at rest in the OS keychain.
            $this->app->singleton(SecretShield::class, SafeStorageSecretShield::class);

            $this->reassertNativePhpSecret($config);
        }
    }

    // NativePHP mints a fresh secret every launch, and a stale Windows config:cache
    // strands a prior launch's value while the live window sends the current one,
    // 403ing every request. getenv() reads the live process environment.
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

        // NOT bundle-gated, so it must precede the return guard below: it has to
        // light up in the spawned queue:work process, which never sets
        // nativephp-internal.running.
        $queueManager = $this->app->make(QueueManager::class);
        $queueManager->looping(static function (): void {
            // max_execution_time is wall-clock on Windows (no ext-pcntl, so Laravel's
            // per-job timeout never arms), and the 120s ceiling would otherwise
            // accrue across the whole long-lived daemon.
            @set_time_limit(0);

            // A force quit never runs the supervisor's before-quit hook, so this
            // worker was left running for hours against a dead app. Between polls is
            // the one place a blocking worker can notice.
            if (HostPipeWatch::hostHasGone()) {
                exit(0);
            }
        });

        // NOT bundle-gated: the pending-intent round-trip must work in local dev and
        // tests too, and the listener touches only the Session contract, no facade.
        $events->listen(Login::class, [ContinuePendingFileIntentAfterLogin::class, 'handle']);

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

        $events->listen(WindowHidden::class, [LockOnWindowHideOrClose::class, 'handle']);
        $events->listen(WindowClosed::class, [LockOnWindowHideOrClose::class, 'handle']);

        $events->listen(NotificationDeepLink::class, [NavigateOnNotificationDeepLink::class, 'handle']);

        // The explicit-consent auto-update chain, in order — discover, consent,
        // install — each step gated on the publisher-signed manifest.
        $events->listen(UpdateAvailable::class, [VerifyAndAnnounceUpdate::class, 'handle']);
        $events->listen(UpdateInstallRequested::class, [TriggerUpdateDownload::class, 'handle']);
        $events->listen(UpdateDownloaded::class, [VerifyAndInstallDownload::class, 'handle']);
    }
}
