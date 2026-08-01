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
use Modules\Core\Public\Support\Lang;

final class ResetPasswordPage extends Component
{
    private const FLASH_KEY = 'flash.reset_password';

    public string $username = '';

    public string $recoveryCode = '';

    public string $newPassword = '';

    public string $newPasswordConfirmation = '';

    public string $flashMessage = '';

    public function submit(ResetPasswordAction $reset, UrlGenerator $urls, Session $session): void
    {
        if ($this->newPassword !== $this->newPasswordConfirmation) {
            $this->flashMessage = Lang::get('auth::reset_password.error_mismatch');
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

        $session->flash(self::FLASH_KEY, Lang::get('auth::reset_password.success'));

        $this->redirect($urls->route('login'), navigate: false);
    }

    public function render(ViewFactory $views): View
    {
        $view = $views->make('auth::livewire.reset-password-page');

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => Lang::get('auth::reset_password.page_title')]);

        return $view;
    }

    // Cleared on every failure path so the plaintext never re-enters the
    // component snapshot.
    private function resetPasswordFields(): void
    {
        $this->newPassword = '';
        $this->newPasswordConfirmation = '';
    }

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

        return Lang::get('auth::reset_password.error_generic');
    }
}
