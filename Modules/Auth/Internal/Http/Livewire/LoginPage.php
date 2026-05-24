<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Http\Livewire;

use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Auth\Public\Actions\LoginAction;

/**
 * The `/login` route landing — the username + password sign-in form.
 *
 * The form binds `username`, `password`, and the `rememberMe` checkbox
 * (checked by default). On submit the page delegates to LoginAction; a
 * successful credential check redirects to the dashboard while a failed
 * one renders a single generic error line and clears the password so the
 * plaintext never re-enters the component snapshot.
 *
 * Constructor-free Livewire component; service collaborators arrive as
 * parameters on the action method and render(). The strict-rules ruleset
 * forbids property-based constructor injection on Component subclasses;
 * `auth()` / `Auth::user()` / facade lookups are out of bounds
 * project-wide.
 */
final class LoginPage extends Component
{
    public string $username = '';

    public string $password = '';

    public bool $rememberMe = true;

    public string $flashMessage = '';

    public function submit(LoginAction $login, UrlGenerator $urls): void
    {
        $succeeded = $login($this->username, $this->password, $this->rememberMe);

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
