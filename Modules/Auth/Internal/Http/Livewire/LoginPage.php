<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Http\Livewire;

use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Auth\Public\Actions\LoginAction;
use Modules\Core\Public\Http\Livewire\Concerns\HoldsFlashMessage;
use Modules\Core\Public\Navigation\Destination;
use Modules\Core\Public\Support\Lang;

final class LoginPage extends Component
{
    use HoldsFlashMessage;

    public string $username = '';

    public string $password = '';

    public bool $rememberMe = true;

    public function submit(LoginAction $login, UrlGenerator $urls): void
    {
        $succeeded = $login($this->username, $this->password, $this->rememberMe);

        // Cleared unconditionally so the plaintext never re-enters the
        // component snapshot on a failed attempt.
        $this->password = '';

        if (! $succeeded) {
            $this->flashMessage = Lang::get('auth::login.error_invalid');

            return;
        }

        $this->redirect(Destination::Dashboard->urlFrom($urls), navigate: false);
    }

    public function render(ViewFactory $views): View
    {
        $view = $views->make('auth::livewire.login-page');

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => Lang::get('auth::login.page_title')]);

        return $view;
    }
}
