<?php

declare(strict_types=1);

namespace Modules\Desktop\Providers;

use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Auth\Public\Contracts\KeyCustodian;
use Modules\Core\Public\Contracts\SecretShield;
use Modules\Desktop\Internal\Http\Livewire\CloseWindowPrompt;
use Modules\Desktop\Internal\Http\Livewire\FileStagingPage;
use Modules\Desktop\Internal\Http\Livewire\SetupScreen;
use Modules\Desktop\Internal\Http\Livewire\WelcomeScreen;
use Modules\Desktop\Internal\Listeners\ApplyCloseWindowChoice;
use Modules\Desktop\Internal\Listeners\ContinuePendingFileIntentAfterLogin;
use Modules\Desktop\Internal\Listeners\DispatchOsNotification;
use Modules\Desktop\Internal\Listeners\HandleNativeOpenFile;
use Modules\Desktop\Internal\Listeners\LockOnWindowHideOrClose;
use Modules\Desktop\Internal\Listeners\NavigateOnNotificationDeepLink;
use Modules\Desktop\Internal\Listeners\SurfaceWorkerCrashAlert;
use Modules\Desktop\Internal\Native\AppMenuBuilder;
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
use Native\Desktop\Events\App\OpenFile;
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

        // NOT YET WIRED — no caller exists; native Touch ID unlock is
        // deferred. Registration stands so wiring is a contract-only
        // change later.
        $this->app->singleton(NativeBiometricUnlock::class);

        // NOT YET WIRED — no caller exists; desktop key custody is
        // deferred. Until wired, the unlocked key follows session
        // custody on all platforms (see LockStateManager).
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
        if (is_dir(__DIR__.'/../Database/Migrations')) {
            $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        }
        if (is_file(__DIR__.'/../Routes/web.php')) {
            $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
        }
        if (is_dir(__DIR__.'/../Resources/views')) {
            $this->loadViewsFrom(__DIR__.'/../Resources/views', 'desktop');
        }

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
        });

        // Subscription is NOT bundle-gated: the pending-intent
        // round-trip must work in local dev / CI / tests too, and the
        // listener touches only the Session contract, no facade.
        $events->listen(Login::class, [ContinuePendingFileIntentAfterLogin::class, 'handle']);

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
    }
}
