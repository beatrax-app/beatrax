<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\Auth\Public\Actions\AddUserAction;
use Modules\Core\Public\Contracts\CurrentUser;

/**
 * The `/settings/users/new` route landing — the owner-adds-partner form.
 *
 * The route is developer-gated; this page collects a username and an
 * initial password, confirms the two password fields match, then
 * delegates to AddUserAction. On success the page flashes the created
 * username and clears the form; the partner's recovery codes are never
 * shown to the owner — the partner sees them after their first sign-in.
 *
 * Constructor-free Livewire component; service collaborators arrive as
 * parameters on the action method and render().
 */
final class AddUserPage extends Component
{
    public string $username = '';

    public string $initialPassword = '';

    public string $initialPasswordConfirmation = '';

    public string $flashMessage = '';

    public function submit(CurrentUser $currentUser, AddUserAction $addUser): void
    {
        if ($this->initialPassword !== $this->initialPasswordConfirmation) {
            $this->flashMessage = 'Passwords do not match.';
            $this->resetPasswordFields();

            return;
        }

        try {
            $partner = $addUser($currentUser->user(), $this->username, $this->initialPassword);
        } catch (ValidationException $e) {
            $this->flashMessage = self::firstErrorMessage($e);
            $this->resetPasswordFields();

            return;
        }

        $this->flashMessage = "User {$partner->username} created. They will set their own password the first time they sign in.";
        $this->username = '';
        $this->resetPasswordFields();
    }

    public function render(ViewFactory $views): View
    {
        $view = $views->make('auth::livewire.add-user-page');

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => 'Add a user · diederik']);

        return $view;
    }

    private function resetPasswordFields(): void
    {
        $this->initialPassword = '';
        $this->initialPasswordConfirmation = '';
    }

    /**
     * Extracts the first message of the first failing field so the page
     * can render a single inline error line.
     */
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

        return 'The user could not be created.';
    }
}
