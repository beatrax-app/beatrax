<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Http\Livewire;

use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Router;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\Auth\Public\Actions\SignupAction;
use Modules\Core\Public\Http\Livewire\Concerns\HoldsFlashMessage;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\ValidationMessages;

// Only reachable while the device has no users; the route guard returns
// a 404 once one exists.
final class SignupPage extends Component
{
    use HoldsFlashMessage;

    public string $username = '';

    public string $password = '';

    public string $passwordConfirmation = '';

    public function submit(SignupAction $signup, UrlGenerator $urls): void
    {
        if ($this->password !== $this->passwordConfirmation) {
            $this->flashMessage = Lang::get('auth::signup.error_mismatch');
            $this->password = '';
            $this->passwordConfirmation = '';

            return;
        }

        try {
            $signup($this->username, $this->password);
        } catch (ValidationException $e) {
            $this->flashMessage = ValidationMessages::first($e, 'auth::signup.error_generic');
            $this->password = '';
            $this->passwordConfirmation = '';

            return;
        }

        // Not straight to setup: that skipped the only screen that ever shows
        // the recovery codes. RecoveryCodesDisplay hands off to setup after.
        $this->redirect($urls->route('auth.recovery-codes-display'), navigate: false);
    }

    public function render(ViewFactory $views, Router $routes, UrlGenerator $urls): View
    {
        $view = $views->make('auth::livewire.signup-page', [
            'backUrl' => $this->welcomeUrl($routes, $urls),
        ]);

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => Lang::get('auth::signup.page_title')]);

        return $view;
    }

    // The screen the reader arrived from. It renders for a guest, so the app
    // chrome that carries a back affordance everywhere else is absent, and the
    // WebView has no back gesture either.
    private function welcomeUrl(Router $routes, UrlGenerator $urls): ?string
    {
        $name = UserDataPathService::isMobileRuntime() ? 'mobile.welcome' : 'desktop.welcome';

        return $routes->has($name) ? $urls->route($name) : null;
    }
}
