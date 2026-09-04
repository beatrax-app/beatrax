<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Http\Livewire;

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Component;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Auth\Internal\Recovery\RecoveryCodeMinter;
use Modules\Auth\Internal\Services\SessionRevoker;
use Modules\Auth\Public\Contracts\PasswordPolicy;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Http\Livewire\Concerns\HoldsFlashMessage;
use Modules\Core\Public\Navigation\Destination;
use Modules\Core\Public\Support\Lang;

// ForcePasswordChangeMiddleware redirects a user carrying the
// force_password_change_at_next_login flag here.
final class ChangePasswordPage extends Component
{
    use HoldsFlashMessage;

    public string $currentPassword = '';

    public string $newPassword = '';

    public string $newPasswordConfirmation = '';

    public function submit(
        CurrentUser $currentUser,
        Hasher $hasher,
        DatabaseManager $db,
        UrlGenerator $urls,
        Session $session,
        AppLockProvisioner $provisioner,
        RecoveryCodeMinter $minter,
        SessionRevoker $sessions,
    ): void {
        $user = $currentUser->user();

        // An empty box is not a wrong answer. Reported as an incorrect password
        // it sends the reader off to check a password manager, when what is
        // wrong is the field in front of them.
        if ($this->currentPassword === '') {
            $this->flashMessage = Lang::get('auth::change_password.error_current_required');

            return;
        }

        $rejection = $this->passwordRejection($hasher, $user->password);

        if ($rejection !== null) {
            $this->flashMessage = $rejection;
            $this->resetPasswordFields();

            return;
        }

        $db->connection()->table('users')
            ->where('id', $user->id)
            ->update([
                'password' => $hasher->make($this->newPassword),
                'force_password_change_at_next_login' => false,
            ]);

        // This is the only moment both passwords exist, and the app-lock
        // recovery wrap is built from the old one — left alone it would stop
        // opening, silently, until the day a forgotten PIN needed it.
        $provisioner->rewrapRecoveryKey($user->id, $this->currentPassword, $this->newPassword);

        // A password changed after a suspected compromise must sever the other
        // sessions; this one survives only to finish the redirect.
        $sessions->revokeAllFor($user->id, $session->getId());

        $codes = $this->mintFirstRecoverySheet($user->id, $db, $minter);

        if ($codes !== []) {
            $session->put(RecoveryCodesDisplay::SESSION_KEY, $codes);
            $session->put(RecoveryCodesDisplay::SESSION_RETURN_KEY, RecoveryCodesDisplay::RETURN_TO_DASHBOARD);

            $this->redirect($urls->route('auth.recovery-codes-display'), navigate: false);

            return;
        }

        $this->redirect(Destination::Dashboard->urlFrom($urls), navigate: false);
    }

    // A partner an owner added holds no sheet: AddUserAction mints none,
    // because at that moment there is nobody to hand it to. This is that
    // moment, and the only one the plaintext can be shown at — the sheet is
    // stored hashed from here on.
    /**
     * @return list<string> empty when this account already has a sheet
     */
    private function mintFirstRecoverySheet(int $userId, DatabaseManager $db, RecoveryCodeMinter $minter): array
    {
        $hasSheet = $db->connection()->table('user_recovery_codes')
            ->where('user_id', $userId)
            ->exists();

        return $hasSheet ? [] : $minter->issueFor($userId);
    }

    public function render(ViewFactory $views): View
    {
        $view = $views->make('auth::livewire.change-password-page');

        $view->extends('layouts.app', ['title' => Lang::get('auth::change_password.page_title')]);

        return $view;
    }

    // The empty-current-password answer above is deliberately not one of these:
    // every rejection here wipes what was typed, and that one must not — the
    // reader has a full form in front of them and one empty box to fill.
    private function passwordRejection(Hasher $hasher, string $currentHash): ?string
    {
        return match (true) {
            ! $hasher->check($this->currentPassword, $currentHash) => Lang::get('auth::change_password.error_current_incorrect'),
            $this->newPassword !== $this->newPasswordConfirmation => Lang::get('auth::change_password.error_mismatch'),
            strlen($this->newPassword) < PasswordPolicy::MINIMUM_LENGTH => Lang::get('auth::change_password.error_min_length'),
            default => null,
        };
    }

    private function resetPasswordFields(): void
    {
        $this->currentPassword = '';
        $this->newPassword = '';
        $this->newPasswordConfirmation = '';
    }
}
