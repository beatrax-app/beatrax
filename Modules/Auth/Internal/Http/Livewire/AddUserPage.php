<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\Auth\Public\Actions\AddUserAction;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Http\Livewire\Concerns\HoldsFlashMessage;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\ValidationMessages;

// Nothing here mints or shows recovery codes: the partner is handed their own
// sheet by ChangePasswordPage, at the forced password change their first
// sign-in lands on.
final class AddUserPage extends Component
{
    use HoldsFlashMessage;

    public string $username = '';

    public string $initialPassword = '';

    public string $initialPasswordConfirmation = '';

    public function submit(CurrentUser $currentUser, AddUserAction $addUser): void
    {
        if ($this->initialPassword !== $this->initialPasswordConfirmation) {
            $this->flashMessage = Lang::get('auth::add_user.error_mismatch');
            $this->resetPasswordFields();

            return;
        }

        try {
            $partner = $addUser($currentUser->user(), $this->username, $this->initialPassword);
        } catch (ValidationException $e) {
            $this->flashMessage = ValidationMessages::first($e, 'auth::add_user.error_generic');
            $this->resetPasswordFields();

            return;
        }

        $this->flashMessage = Lang::get('auth::add_user.created', ['name' => $partner->username]);
        $this->username = '';
        $this->resetPasswordFields();
    }

    public function render(ViewFactory $views): View
    {
        $view = $views->make('auth::livewire.add-user-page');

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => Lang::get('auth::add_user.page_title')]);

        return $view;
    }

    private function resetPasswordFields(): void
    {
        $this->initialPassword = '';
        $this->initialPasswordConfirmation = '';
    }
}
