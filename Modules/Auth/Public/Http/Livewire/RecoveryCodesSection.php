<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Http\Livewire;

use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Auth\Internal\Http\Livewire\RecoveryCodesDisplay;
use Modules\Auth\Internal\Lock\AppLockCredentialRejections;
use Modules\Auth\Public\Actions\RegenerateRecoveryCodesAction;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Http\Livewire\Concerns\HoldsFlashMessage;

// Codes are stored hashed and shown once, so the only honest way back is to
// mint a fresh set. The plaintext travels by session, never a public property.
final class RecoveryCodesSection extends Component
{
    use HoldsFlashMessage;

    // A sheet outlives the session that asked for it, and the password change
    // that ends every other session does not retire one. Without this box a
    // borrowed unlocked session bought permanent entry, so this is the same
    // proof the account-deletion section below it has always taken.
    public string $accountPassword = '';

    public function regenerate(
        RegenerateRecoveryCodesAction $regenerate,
        CurrentUser $currentUser,
        Session $session,
        UrlGenerator $urls,
        AppLockCredentialRejections $rejections,
    ): void {
        $user = $currentUser->user();

        $rejection = $rejections->accountPassword($this->accountPassword, $user->password);

        if ($rejection !== null) {
            $this->flashMessage = $rejection;

            return;
        }

        $this->accountPassword = '';
        $this->flashMessage = '';

        // No developer role needed: the action admits a caller acting on
        // themselves, and refuses anyone else with a 404 rather than a 403.
        $codes = $regenerate($user, $user->username);

        $session->put(RecoveryCodesDisplay::SESSION_KEY, $codes);
        $session->put(RecoveryCodesDisplay::SESSION_RETURN_KEY, RecoveryCodesDisplay::RETURN_TO_SETTINGS);

        $this->redirect($urls->route('auth.recovery-codes-display'), navigate: false);
    }

    public function render(ViewFactory $views): View
    {
        return $views->make('auth::livewire.recovery-codes-section');
    }
}
