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
use Modules\Auth\Public\Contracts\PasswordPolicy;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Http\Livewire\Concerns\HoldsFlashMessage;
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
    ): void {
        $user = $currentUser->user();

        if (! $hasher->check($this->currentPassword, $user->password)) {
            $this->flashMessage = Lang::get('auth::change_password.error_current_incorrect');
            $this->resetPasswordFields();

            return;
        }

        if ($this->newPassword !== $this->newPasswordConfirmation) {
            $this->flashMessage = Lang::get('auth::change_password.error_mismatch');
            $this->resetPasswordFields();

            return;
        }

        if (strlen($this->newPassword) < PasswordPolicy::MINIMUM_LENGTH) {
            $this->flashMessage = Lang::get('auth::change_password.error_min_length');
            $this->resetPasswordFields();

            return;
        }

        $db->connection()->table('users')
            ->where('id', $user->id)
            ->update([
                'password' => $hasher->make($this->newPassword),
                'force_password_change_at_next_login' => false,
            ]);

        // A password changed after a suspected compromise must sever the other
        // sessions; this one survives only to finish the redirect.
        $db->connection()->table('sessions')
            ->where('user_id', $user->id)
            ->where('id', '!=', $session->getId())
            ->delete();

        $this->redirect($urls->route('dashboard'), navigate: false);
    }

    public function render(ViewFactory $views): View
    {
        $view = $views->make('auth::livewire.change-password-page');

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => Lang::get('auth::change_password.page_title')]);

        return $view;
    }

    private function resetPasswordFields(): void
    {
        $this->currentPassword = '';
        $this->newPassword = '';
        $this->newPasswordConfirmation = '';
    }
}
