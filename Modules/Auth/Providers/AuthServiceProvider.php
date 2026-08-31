<?php

declare(strict_types=1);

namespace Modules\Auth\Providers;

use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Auth\Internal\Console\GrantDevCommand;
use Modules\Auth\Internal\Console\RegenerateRecoveryCodesCommand;
use Modules\Auth\Internal\Console\ResetPasswordCommand;
use Modules\Auth\Internal\Fortify\FortifyServiceProvider;
use Modules\Auth\Internal\Http\Livewire\AddUserPage;
use Modules\Auth\Internal\Http\Livewire\ChangePasswordPage;
use Modules\Auth\Internal\Http\Livewire\LockScreen;
use Modules\Auth\Internal\Http\Livewire\LoginPage;
use Modules\Auth\Internal\Http\Livewire\ManageUserPage;
use Modules\Auth\Internal\Http\Livewire\RecoveryCodesDisplay;
use Modules\Auth\Internal\Http\Livewire\ResetPasswordPage;
use Modules\Auth\Internal\Http\Livewire\SignupPage;
use Modules\Auth\Internal\Http\Middleware\AppLockMiddleware;
use Modules\Auth\Internal\Http\Middleware\FirstUserOnlyMiddleware;
use Modules\Auth\Internal\Http\Middleware\ForcePasswordChangeMiddleware;
use Modules\Auth\Internal\Http\Middleware\RequireDeveloperMiddleware;
use Modules\Auth\Internal\Listeners\StartLockedOnLogin;
use Modules\Auth\Internal\Lock\AppLockKdf;
use Modules\Auth\Internal\Lock\AppLockKeyWrap;
use Modules\Auth\Internal\Lock\BiometricDeviceStore;
use Modules\Auth\Internal\Lock\NullColdStartVault;
use Modules\Auth\Internal\Lock\NullKeyCustodian;
use Modules\Auth\Internal\Lock\PinHasher;
use Modules\Auth\Internal\Lock\PinVerificationService;
use Modules\Auth\Internal\Lock\PlatformDetector;
use Modules\Auth\Internal\Recovery\RecoveryCodeGenerator;
use Modules\Auth\Internal\Recovery\RecoveryCodeMinter;
use Modules\Auth\Internal\Recovery\RecoveryCodeNormalizer;
use Modules\Auth\Public\Actions\LoginAction;
use Modules\Auth\Public\Actions\RegenerateRecoveryCodesAction;
use Modules\Auth\Public\Contracts\ColdStartVault;
use Modules\Auth\Public\Contracts\KeyCustodian;
use Modules\Auth\Public\Http\Livewire\AppLockKeyProbe;
use Modules\Auth\Public\Http\Livewire\AppLockSettingsSection;
use Modules\Auth\Public\Http\Livewire\DeleteAccountSection;
use Modules\Auth\Public\Http\Livewire\RecoveryCodesSection;
use Modules\Auth\Public\Recovery\RecoveryCodeFormatter;
use Modules\Auth\Public\Services\AppLockClientConfig;
use Modules\Auth\Public\Services\BiometricKeyBlobCodec;
use Modules\Core\Public\Support\LoadsModuleResources;

final class AuthServiceProvider extends ServiceProvider
{
    use LoadsModuleResources;

    public function register(): void
    {
        // Overridden by the desktop and mobile runtimes; elsewhere the lock
        // screen finds no OS vault and offers PIN + WebAuthn only.
        $this->app->singleton(ColdStartVault::class, NullColdStartVault::class);

        $this->app->register(FortifyServiceProvider::class);

        $this->app->singleton(RegenerateRecoveryCodesAction::class);
        $this->app->singleton(RecoveryCodeGenerator::class);
        $this->app->singleton(RecoveryCodeMinter::class);
        $this->app->singleton(RecoveryCodeFormatter::class);
        $this->app->singleton(RecoveryCodeNormalizer::class);

        // Key custody while unlocked. The pass-through default keeps the key
        // in the session; the bundles override it onto the OS keychain.
        $this->app->singleton(KeyCustodian::class, NullKeyCustodian::class);

        $this->app->singleton(PinHasher::class);
        $this->app->singleton(AppLockKdf::class);
        $this->app->singleton(AppLockKeyWrap::class);
        $this->app->singleton(BiometricKeyBlobCodec::class);
        $this->app->singleton(AppLockClientConfig::class);
        $this->app->singleton(PinVerificationService::class);

        $this->app->singleton(BiometricDeviceStore::class);
        $this->app->singleton(PlatformDetector::class);
    }

    public function boot(Dispatcher $events, LivewireManager $livewire, Router $router): void
    {
        $this->loadModuleResources('auth');
        $this->loadRoutesFrom(__DIR__.'/../Routes/console.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                ResetPasswordCommand::class,
                GrantDevCommand::class,
                RegenerateRecoveryCodesCommand::class,
            ]);
        }

        // Fires on the recaller path too, which reaches neither LoginAction
        // nor Fortify's pipeline and so primed nothing at all.
        $events->listen(Login::class, [StartLockedOnLogin::class, 'handle']);

        $router->aliasMiddleware('first-user-only', FirstUserOnlyMiddleware::class);
        $router->aliasMiddleware('developer', RequireDeveloperMiddleware::class);

        $router->pushMiddlewareToGroup('auth', AppLockMiddleware::class);

        // Exempts the change-password page and logout by name, or a flagged
        // user is trapped.
        $router->pushMiddlewareToGroup('auth', ForcePasswordChangeMiddleware::class);

        // The `auth` group above shadows the framework's `auth` alias, so the
        // framework middleware is prepended to keep guests out.
        $router->prependMiddlewareToGroup('auth', Authenticate::class);

        // Livewire's update endpoint runs outside the route middleware group,
        // so without these a locked session — or one flagged for a forced
        // password change, which is the answer to a suspected compromise —
        // keeps driving every component whose snapshot it already holds.
        $livewire->addPersistentMiddleware(AppLockMiddleware::class);
        $livewire->addPersistentMiddleware(ForcePasswordChangeMiddleware::class);

        $livewire->component('auth.login-page', LoginPage::class);
        $livewire->component('auth.signup-page', SignupPage::class);
        $livewire->component('auth.recovery-codes-display', RecoveryCodesDisplay::class);
        $livewire->component('auth.recovery-codes-section', RecoveryCodesSection::class);
        $livewire->component('auth.delete-account-section', DeleteAccountSection::class);
        $livewire->component('auth.change-password-page', ChangePasswordPage::class);
        $livewire->component('auth.add-user-page', AddUserPage::class);
        $livewire->component('auth.reset-password-page', ResetPasswordPage::class);
        $livewire->component('auth.manage-user-page', ManageUserPage::class);

        $livewire->component('auth.lock-screen', LockScreen::class);
        $livewire->component('auth.app-lock-settings-section', AppLockSettingsSection::class);

        $livewire->component('auth.app-lock-key-probe', AppLockKeyProbe::class);
    }
}
