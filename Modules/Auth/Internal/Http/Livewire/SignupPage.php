<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Http\Livewire;

use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\Auth\Public\Actions\SignupAction;

// Only reachable while the device has no users; the route guard returns
// a 404 once one exists.
final class SignupPage extends Component
{
    public string $username = '';

    public string $password = '';

    public string $passwordConfirmation = '';

    public string $flashMessage = '';

    public function submit(SignupAction $signup, UrlGenerator $urls): void
    {
        if ($this->password !== $this->passwordConfirmation) {
            $this->flashMessage = 'Passwords do not match.';
            $this->password = '';
            $this->passwordConfirmation = '';

            return;
        }

        try {
            $signup($this->username, $this->password);
        } catch (ValidationException $e) {
            $this->flashMessage = self::firstErrorMessage($e);
            $this->password = '';
            $this->passwordConfirmation = '';

            return;
        }

        $this->redirect($urls->route('setup'), navigate: false);
    }

    public function render(ViewFactory $views): View
    {
        $view = $views->make('auth::livewire.signup-page');

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => 'Welcome to beatrax · beatrax']);

        return $view;
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

        return 'Signup could not be completed.';
    }
}
