<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Http\Livewire;

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Component;
use Modules\Auth\Public\Actions\RegenerateRecoveryCodesAction;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\Lang;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// Raises a 404 -- never a 403 -- when the partner does not exist or the
// current user is not a developer, so the route never reveals its own
// existence to a non-owner.
final class ManageUserPage extends Component
{
    private const MINIMUM_PASSWORD_LENGTH = 12;

    public string $partnerUsername = '';

    public string $newPartnerPassword = '';

    public string $flashMessage = '';

    /** @var list<string> */
    public array $regeneratedCodes = [];

    public function mount(string $username, CurrentUser $currentUser): void
    {
        if ($currentUser->user()->is_developer !== true) {
            throw new NotFoundHttpException;
        }

        $normalised = strtolower(trim($username));

        $partner = User::query()->where('username', $normalised)->first();

        if (! $partner instanceof User) {
            throw new NotFoundHttpException;
        }

        $this->partnerUsername = $partner->username;
    }

    public function setPartnerPassword(Hasher $hasher, DatabaseManager $db): void
    {
        if (strlen($this->newPartnerPassword) < self::MINIMUM_PASSWORD_LENGTH) {
            $this->flashMessage = Lang::get('auth::manage_user.error_min_length');
            $this->newPartnerPassword = '';

            return;
        }

        $db->connection()->table('users')
            ->where('username', $this->partnerUsername)
            ->update([
                'password' => $hasher->make($this->newPartnerPassword),
                'force_password_change_at_next_login' => true,
            ]);

        $this->newPartnerPassword = '';
        $this->flashMessage = Lang::get('auth::manage_user.password_set', ['name' => $this->partnerUsername]);
    }

    public function regenerateCodes(RegenerateRecoveryCodesAction $regenerate, CurrentUser $currentUser): void
    {
        $this->regeneratedCodes = $regenerate($currentUser->user(), $this->partnerUsername);
        $this->flashMessage = Lang::get('auth::manage_user.codes_regenerated', ['name' => $this->partnerUsername]);
    }

    public function render(ViewFactory $views): View
    {
        $view = $views->make('auth::livewire.manage-user-page');

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => Lang::get('auth::manage_user.page_title', ['name' => $this->partnerUsername])]);

        return $view;
    }
}
