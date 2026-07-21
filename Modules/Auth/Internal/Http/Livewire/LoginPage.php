<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Http\Livewire;

use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Auth\Public\Actions\LoginAction;

final class LoginPage extends Component
{
    public string $username = '';

    public string $password = '';

    public bool $rememberMe = true;

    public string $flashMessage = '';

    public function submit(LoginAction $login, UrlGenerator $urls): void
    {
        $succeeded = $login($this->username, $this->password, $this->rememberMe);

        // Cleared unconditionally so the plaintext never re-enters the
        // component snapshot, on success or failure.
        $this->password = '';

        if (! $succeeded) {
            $this->flashMessage = 'Username or password is incorrect.';

            return;
        }

        $this->redirect($urls->route('dashboard'), navigate: false);
    }

    public function render(ViewFactory $views): View
    {
        $view = $views->make('auth::livewire.login-page');

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => 'Sign in · beatrax']);

        return $view;
    }
}
