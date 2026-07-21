<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Http\Livewire;

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;

// ForcePasswordChangeMiddleware redirects a user carrying the
// force_password_change_at_next_login flag here.
final class ChangePasswordPage extends Component
{
    private const MINIMUM_PASSWORD_LENGTH = 12;

    public string $currentPassword = '';

    public string $newPassword = '';

    public string $newPasswordConfirmation = '';

    public string $flashMessage = '';

    public function submit(
        CurrentUser $currentUser,
        Hasher $hasher,
        DatabaseManager $db,
        UrlGenerator $urls,
    ): void {
        $user = $currentUser->user();

        if (! $hasher->check($this->currentPassword, $user->password)) {
            $this->flashMessage = 'Current password is incorrect.';
            $this->resetPasswordFields();

            return;
        }

        if ($this->newPassword !== $this->newPasswordConfirmation) {
            $this->flashMessage = 'Passwords do not match.';
            $this->resetPasswordFields();

            return;
        }

        if (strlen($this->newPassword) < self::MINIMUM_PASSWORD_LENGTH) {
            $this->flashMessage = 'Use at least 12 characters.';
            $this->resetPasswordFields();

            return;
        }

        $db->connection()->table('users')
            ->where('id', $user->id)
            ->update([
                'password' => $hasher->make($this->newPassword),
                'force_password_change_at_next_login' => false,
            ]);

        $this->redirect($urls->route('dashboard'), navigate: false);
    }

    public function render(ViewFactory $views): View
    {
        $view = $views->make('auth::livewire.change-password-page');

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => 'Set a new password · beatrax']);

        return $view;
    }

    private function resetPasswordFields(): void
    {
        $this->currentPassword = '';
        $this->newPassword = '';
        $this->newPasswordConfirmation = '';
    }
}
