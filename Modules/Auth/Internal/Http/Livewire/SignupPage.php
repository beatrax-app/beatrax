<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Http\Livewire;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Foundation\Application;
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
use Modules\Core\Public\Services\LocaleNegotiator;
use Modules\Core\Public\Services\UserCountry;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\ValidationMessages;

// Only reachable while the device has no users; the route guard returns
// a 404 once one exists.
final class SignupPage extends Component
{
    use HoldsFlashMessage;

    // The three boxes on the form. SignupAction also rejects under `signup`
    // when the device gained an owner mid-submit, and that has no box to sit
    // under, so it stays on the form-level line.
    private const array FIELD_KEYS = ['username', 'password', 'passwordConfirmation'];

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
            $this->reportRejection($e);

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

    // "System" is the absence of an override rather than a locale, so it clears
    // the key instead of storing a code — the same rule the POST route follows.
    // Without this arm the reader could leave System but never return to it.
    public function updatedLocale(Session $session, Application $app): void
    {
        if ($this->locale === LocaleNegotiator::SYSTEM) {
            $session->forget('locale');

            return;
        }

        if (! Locale::isSupported($this->locale)) {
            return;
        }

        $session->put('locale', $this->locale);
        $app->setLocale($this->locale);
        CarbonImmutable::setLocale($this->locale);
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

    // Field-scoped, so the message renders under the box it is about and the
    // control carries aria-invalid. One shared form-level line put the
    // username error below two other fields and the checklist, out of sight
    // behind a raised keyboard and unannounced on the field it described.
    private function reportRejection(ValidationException $exception): void
    {
        $placed = false;
        $errors = $exception->validator->errors()->messages();

        foreach (self::FIELD_KEYS as $field) {
            foreach ($errors[$field] ?? [] as $message) {
                $this->addError($field, $message);
                $placed = true;
            }
        }

        if (! $placed) {
            $this->flashMessage = ValidationMessages::first($exception, 'auth::signup.error_generic');
        }
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
