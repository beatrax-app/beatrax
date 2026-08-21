<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Http\Livewire;

use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\Auth\Public\Actions\SignupAction;
use Modules\Core\Public\Http\Livewire\Concerns\HoldsFlashMessage;
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

    public function render(ViewFactory $views): View
    {
        $view = $views->make('auth::livewire.signup-page');

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => Lang::get('auth::signup.page_title')]);

        return $view;
    }
}
