<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Http\Livewire;

use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Session\Store as Session;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Auth\Public\Actions\LoginAction;
use Modules\Auth\Public\Exceptions\SignInThrottled;
use Modules\Core\Public\Http\Livewire\Concerns\HoldsFlashMessage;
use Modules\Core\Public\Http\Livewire\EncryptedBackupRestore;
use Modules\Core\Public\Navigation\Destination;
use Modules\Core\Public\Support\Lang;

final class LoginPage extends Component
{
    use HoldsFlashMessage;

    public string $username = '';

    public string $password = '';

    public bool $rememberMe = true;

    public string $recoveryCode = '';

    // Server-owned: the meter decides this, never the browser. It is what the
    // reader is shown of the way past a spent meter, and it is shown for a
    // username nobody has exactly as for one somebody does, because the meter
    // is consulted before the account is ever looked up.
    #[Locked]
    public bool $recoveryOffered = false;

    // A restore replaces the identity along with everything else, so it signs
    // the reader out and lands here. Without this the screen that follows the
    // one irreversible action in the app says nothing about whether it worked.
    public string $restored = '';

    public string $snapshotPath = '';

    public function mount(Session $session): void
    {
        if (! $session->has(EncryptedBackupRestore::SNAPSHOT_FLASH_KEY)) {
            return;
        }

        $path = $session->get(EncryptedBackupRestore::SNAPSHOT_FLASH_KEY);

        $this->restored = Lang::get('core::backup.restore.restored');
        $this->snapshotPath = is_string($path) ? $path : '';
    }

    public function submit(LoginAction $login, UrlGenerator $urls): void
    {
        try {
            $succeeded = $login($this->username, $this->password, $this->rememberMe, $this->recoveryCode);
        } catch (SignInThrottled $throttled) {
            $this->refuseWhileMetered($throttled);

            return;
        }

        $this->forgetWhatWasTyped();

        // Nothing reaches here while the meter stands, so the way past it
        // leaves the screen with it.
        $this->recoveryOffered = false;

        if (! $succeeded) {
            $this->flashMessage = Lang::get('auth::login.error_invalid');

            return;
        }

        $this->redirect(Destination::Dashboard->urlFrom($urls), navigate: false);
    }

    // Said plainly rather than folded into error_invalid: a reader who is
    // waiting and a reader who is wrong take different actions, and only one of
    // them is helped by trying again immediately. Both sentences are the
    // meter's, not the account's — neither has looked one up.
    private function refuseWhileMetered(SignInThrottled $throttled): void
    {
        $this->forgetWhatWasTyped();
        $this->recoveryOffered = true;

        $this->flashMessage = $throttled->recoveryCodeRejected
            ? Lang::get('auth::reset_password.error_wrong_code')
            : Lang::get('auth::login.error_throttled', ['wait' => $throttled->secondsRemaining.'s']);
    }

    // Called on every outcome so neither plaintext re-enters the component
    // snapshot on an attempt that failed.
    private function forgetWhatWasTyped(): void
    {
        $this->password = '';
        $this->recoveryCode = '';
    }

    public function render(ViewFactory $views): View
    {
        $view = $views->make('auth::livewire.login-page');

        $view->extends('layouts.app', ['title' => Lang::get('auth::login.page_title')]);

        return $view;
    }
}
