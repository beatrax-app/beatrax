<?php

declare(strict_types=1);

namespace Modules\Auth\Providers;

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
use Modules\Auth\Internal\Http\Livewire\AppLockKeyProbe;
use Modules\Auth\Internal\Http\Livewire\AppLockSettingsSection;
use Modules\Auth\Internal\Http\Livewire\ChangePasswordPage;
use Modules\Auth\Internal\Http\Livewire\DeleteAccountSection;
use Modules\Auth\Internal\Http\Livewire\LockScreen;
use Modules\Auth\Internal\Http\Livewire\LoginPage;
use Modules\Auth\Internal\Http\Livewire\ManageUserPage;
use Modules\Auth\Internal\Http\Livewire\RecoveryCodesDisplay;
use Modules\Auth\Internal\Http\Livewire\RecoveryCodesSection;
use Modules\Auth\Internal\Http\Livewire\ResetPasswordPage;
use Modules\Auth\Internal\Http\Livewire\SignupPage;
use Modules\Auth\Internal\Http\Middleware\AppLockMiddleware;
use Modules\Auth\Internal\Http\Middleware\FirstUserOnlyMiddleware;
use Modules\Auth\Internal\Http\Middleware\ForcePasswordChangeMiddleware;
use Modules\Auth\Internal\Http\Middleware\RequireDeveloperMiddleware;
use Modules\Auth\Internal\Lock\AppLockKdf;
use Modules\Auth\Internal\Lock\AppLockKeyWrap;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Auth\Internal\Lock\BiometricDeviceStore;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Auth\Internal\Lock\NullColdStartVault;
use Modules\Auth\Internal\Lock\NullKeyCustodian;
use Modules\Auth\Internal\Lock\PinHasher;
use Modules\Auth\Internal\Lock\PinVerificationService;
use Modules\Auth\Internal\Lock\PlatformDetector;
use Modules\Auth\Internal\Lock\WebAuthnBiometricService;
use Modules\Auth\Internal\Recovery\RecoveryCodeAuthenticator;
use Modules\Auth\Internal\Recovery\RecoveryCodeGenerator;
use Modules\Auth\Internal\Recovery\RecoveryCodeNormalizer;
use Modules\Auth\Public\Actions\AddUserAction;
use Modules\Auth\Public\Actions\LoginAction;
use Modules\Auth\Public\Actions\LogoutAction;
use Modules\Auth\Public\Actions\RegenerateRecoveryCodesAction;
use Modules\Auth\Public\Actions\ResetPasswordAction;
use Modules\Auth\Public\Actions\SignupAction;
use Modules\Auth\Public\Contracts\ColdStartVault;
use Modules\Auth\Public\Contracts\KeyCustodian;
use Modules\Auth\Public\Recovery\RecoveryCodeFormatter;
use Modules\Auth\Public\Services\AppLockClientConfig;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Auth\Public\Services\BiometricKeyBlobCodec;
use Modules\Core\Public\Support\LoadsModuleResources;

/**
 * @link ../../../.docs/features/auth/architecture.md
 */
final class AuthServiceProvider extends ServiceProvider
{
    use LoadsModuleResources;

    public function register(): void
    {
        // Overridden by the desktop (Touch ID) and mobile (enclave) bindings
        // inside their own runtimes; everywhere else the lock screen simply
        // finds no OS-gated vault and offers PIN + WebAuthn only.
        $this->app->singleton(ColdStartVault::class, NullColdStartVault::class);

        $this->app->register(FortifyServiceProvider::class);

        $this->app->singleton(LoginAction::class);
        $this->app->singleton(LogoutAction::class);
        $this->app->singleton(SignupAction::class);
        $this->app->singleton(AddUserAction::class);
        $this->app->singleton(ResetPasswordAction::class);
        $this->app->singleton(RegenerateRecoveryCodesAction::class);
        $this->app->singleton(RecoveryCodeGenerator::class);
        $this->app->singleton(RecoveryCodeFormatter::class);
        $this->app->singleton(RecoveryCodeNormalizer::class);
        $this->app->singleton(RecoveryCodeAuthenticator::class);

        // At-rest key custody while unlocked. Defaults to the pass-through
        // NullKeyCustodian (session-held key, unchanged web behaviour); the
        // Desktop / Mobile providers override this binding on their bundles
        // to route the key through the OS keychain / Keystore.
        $this->app->singleton(KeyCustodian::class, NullKeyCustodian::class);

        $this->app->singleton(PinHasher::class);
        $this->app->singleton(AppLockKdf::class);
        $this->app->singleton(AppLockKeyWrap::class);
        $this->app->singleton(BiometricKeyBlobCodec::class);
        $this->app->singleton(LockStateManager::class);
        $this->app->singleton(AppLockKeyService::class);
        $this->app->singleton(AppLockClientConfig::class);
        $this->app->singleton(PinVerificationService::class);
        $this->app->singleton(AppLockProvisioner::class);

        $this->app->singleton(BiometricDeviceStore::class);
        $this->app->singleton(PlatformDetector::class);
        $this->app->singleton(WebAuthnBiometricService::class);
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

        $router->aliasMiddleware('first-user-only', FirstUserOnlyMiddleware::class);
        $router->aliasMiddleware('developer', RequireDeveloperMiddleware::class);

        // Gates every authenticated route behind the app-lock screen; the
        // middleware exempts auth.lock + logout to prevent redirect loops.
        $router->pushMiddlewareToGroup('auth', AppLockMiddleware::class);

        // Enforce the forced-password-change flag on every authenticated
        // route. The middleware exempts the change-password page and the
        // logout route by name so a flagged user is never trapped.
        $router->pushMiddlewareToGroup('auth', ForcePasswordChangeMiddleware::class);

        // Defining an `auth` middleware group above shadows the framework's
        // `auth` middleware alias on every `->middleware('auth')` route.
        // Prepend the framework authentication middleware so the group still
        // rejects guests before the module middleware run.
        $router->prependMiddlewareToGroup('auth', Authenticate::class);

        // Re-runs the lock gate on every Livewire component update request
        // so a locked session cannot bypass the gate via /livewire/update.
        $livewire->addPersistentMiddleware(AppLockMiddleware::class);

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
