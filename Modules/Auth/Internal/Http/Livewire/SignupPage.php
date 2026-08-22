<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Http\Livewire;

use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Router;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\Auth\Public\Actions\SignupAction;
use Modules\Core\Public\Enums\Locale;
use Modules\Core\Public\Http\Livewire\Concerns\HoldsFlashMessage;
use Modules\Core\Public\Http\Livewire\Concerns\ReportsFieldRejections;
use Modules\Core\Public\Services\LocaleNegotiator;
use Modules\Core\Public\Services\UserCountry;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Core\Public\Support\Lang;

// Only reachable while the device has no users; the route guard returns
// a 404 once one exists.
final class SignupPage extends Component
{
    use HoldsFlashMessage;
    use ReportsFieldRejections;

    // The three boxes on the form, read by ReportsFieldRejections rather than
    // by anything here. SignupAction also rejects under `signup` when the
    // device gained an owner mid-submit, and that has no box to sit under, so
    // it stays on the form-level line.
    protected const array FIELD_KEYS = ['username', 'password', 'passwordConfirmation'];

    public string $username = '';

    public string $password = '';

    public string $passwordConfirmation = '';

    // Skipping is a real answer: an unset country widens classification to
    // every region rather than pinning the install to a guessed one.
    public string $country = '';

    public string $locale = '';

    // A rejected submit leaves both password boxes as the reader typed them.
    // Emptying them turned a username error into a retyped 12-character
    // passphrase on a phone keyboard, and left the live checklist ticking
    // requirements about boxes that no longer held anything.
    public function submit(SignupAction $signup, UrlGenerator $urls): void
    {
        $this->resetErrorBag();
        $this->flashMessage = '';

        if ($this->password !== $this->passwordConfirmation) {
            $this->addError('passwordConfirmation', Lang::get('auth::signup.error_mismatch'));

            return;
        }

        try {
            $signup($this->username, $this->password, countryCode: $this->country);
        } catch (ValidationException $e) {
            $this->reportRejection($e, 'auth::signup.error_generic');

            return;
        }

        // Not straight to setup: that skipped the only screen that ever shows
        // the recovery codes. RecoveryCodesDisplay hands off to setup after.
        $this->redirect($urls->route('auth.recovery-codes-display'), navigate: false);
    }

    // The shared switcher POSTs and navigates, which emptied every box on
    // this screen and reset the country to its placeholder. Here the language
    // changes over a Livewire round trip instead, so what the reader has
    // already typed is still typed afterwards.
    public function mount(Session $session): void
    {
        $stored = $session->get('locale');
        $this->locale = is_string($stored) && Locale::isSupported($stored)
            ? $stored
            : LocaleNegotiator::SYSTEM;
    }

    // Remembered for the next full page load, and applied to this one: the
    // screen re-renders over a Livewire round trip rather than navigating, so
    // nothing else would retarget the language before it is drawn again.
    public function updatedLocale(Session $session, LocaleNegotiator $negotiator): void
    {
        $negotiator->rememberChoice($session, $this->locale);

        if (Locale::isSupported($this->locale)) {
            $negotiator->apply($this->locale);
        }
    }

    public function render(ViewFactory $views, Router $routes, UrlGenerator $urls, UserCountry $countries): View
    {
        $view = $views->make('auth::livewire.signup-page', [
            'backUrl' => $this->welcomeUrl($routes, $urls),
            'countryOptions' => $countries->options(),
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
