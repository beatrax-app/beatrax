<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Http\Livewire;

use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\Auth\Public\Actions\ResetPasswordAction;

/**
 * The `/reset-password` route landing — the recovery-code-driven
 * password reset form.
 *
 * A user who has lost their password types their username, one recovery
 * code, and a new password. The page first checks the two new-password
 * fields match, then delegates to ResetPasswordAction, which verifies
 * the username + code (consuming the code on a match) and writes the new
 * password. On success the page flashes a confirmation message and
 * redirects to `/login` so the user signs in fresh. A wrong code or a
 * short / mismatched password renders a single inline error line and
 * clears the password fields so plaintext never re-enters the snapshot.
 *
 * Constructor-free Livewire component; service collaborators arrive as
 * parameters on the action method and render().
 */
final class ResetPasswordPage extends Component
{
    /** Session key the login page reads to surface the success flash. */
    private const FLASH_KEY = 'flash.reset_password';

    public string $username = '';

    public string $recoveryCode = '';

    public string $newPassword = '';

    public string $newPasswordConfirmation = '';

    public string $flashMessage = '';

    public function submit(ResetPasswordAction $reset, UrlGenerator $urls, Session $session): void
    {
        if ($this->newPassword !== $this->newPasswordConfirmation) {
            $this->flashMessage = 'Passwords do not match.';
            $this->resetPasswordFields();

            return;
        }

        try {
            $reset($this->username, $this->recoveryCode, $this->newPassword);
        } catch (ValidationException $e) {
            $this->flashMessage = self::firstErrorMessage($e);
            $this->resetPasswordFields();

            return;
        }

        $session->flash(self::FLASH_KEY, 'Password updated. Sign in with your new password.');

        $this->redirect($urls->route('login'), navigate: false);
    }

    public function render(ViewFactory $views): View
    {
        $view = $views->make('auth::livewire.reset-password-page');

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => 'Reset your password · beatrax']);

        return $view;
    }

    private function resetPasswordFields(): void
    {
        $this->newPassword = '';
        $this->newPasswordConfirmation = '';
    }

    /**
     * Extracts the first message of the first failing field so the page
     * can render a single inline error line.
     */
    private static function firstErrorMessage(ValidationException $exception): string
    {
        /** @var array<string, mixed> $errors */
        $errors = $exception->errors();

        foreach ($errors as $messages) {
            if (! is_array($messages)) {
                continue;
            }

            foreach ($messages as $message) {
                if (is_string($message) && $message !== '') {
                    return $message;
                }
            }
        }

        return 'The password could not be reset.';
    }
}
