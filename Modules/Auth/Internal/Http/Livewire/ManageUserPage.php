<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Http\Livewire;

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Auth\Internal\Services\AccountOwner;
use Modules\Auth\Internal\Services\SessionRevoker;
use Modules\Auth\Public\Actions\RegenerateRecoveryCodesAction;
use Modules\Auth\Public\Contracts\PasswordPolicy;
use Modules\Auth\Public\Recovery\RecoveryCodeFormatter;
use Modules\Auth\Public\Support\Username;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Http\Livewire\Concerns\HoldsFlashMessage;
use Modules\Core\Public\Support\Lang;
use Modules\Mobile\Public\Services\ShareSheetExport;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// A 404 and never a 403, so the route never reveals its own existence to a
// non-owner. Every entry point re-checks ownership: the route middleware does
// not re-run on a Livewire update.
final class ManageUserPage extends Component
{
    use HoldsFlashMessage;

    // Locked so a Livewire update cannot retarget the password reset at another
    // account: mount() is the only writer, and it gates before setting this.
    #[Locked]
    public string $partnerUsername = '';

    public string $newPartnerPassword = '';

    /** @var list<string> */
    public array $regeneratedCodes = [];

    public function mount(string $username, CurrentUser $currentUser, AccountOwner $owner): void
    {
        if (! $owner->isOwner($currentUser->user())) {
            throw new NotFoundHttpException;
        }

        $normalised = Username::normalize($username);

        $partner = User::query()->where('username', $normalised)->first();

        if (! $partner instanceof User) {
            throw new NotFoundHttpException;
        }

        $this->partnerUsername = $partner->username;
    }

    public function setPartnerPassword(
        Hasher $hasher,
        DatabaseManager $db,
        CurrentUser $currentUser,
        AppLockProvisioner $provisioner,
        AccountOwner $owner,
        SessionRevoker $sessions,
    ): void {
        // The route middleware does not re-run on a Livewire update, so an
        // owner who is no longer the owner mid-session kept resetting passwords.
        if (! $owner->isOwner($currentUser->user())) {
            throw new NotFoundHttpException;
        }

        if (strlen($this->newPartnerPassword) < PasswordPolicy::MINIMUM_LENGTH) {
            $this->flashMessage = Lang::get('auth::manage_user.error_min_length');
            $this->newPartnerPassword = '';

            return;
        }

        $partnerId = $db->connection()->table('users')->where('username', $this->partnerUsername)->value('id');

        $db->connection()->table('users')
            ->where('username', $this->partnerUsername)
            ->update([
                'password' => $hasher->make($this->newPartnerPassword),
                'force_password_change_at_next_login' => true,
            ]);

        // The owner sets this password without holding the old one, so the
        // partner's app-lock recovery wrap cannot be carried over — and the
        // forced change at their next sign-in cannot carry it either. That
        // flag is a redirect, so it never severed the partner's own sessions.
        if (is_numeric($partnerId)) {
            $provisioner->markRecoveryWrapStale((int) $partnerId);
            $sessions->revokeAllFor((int) $partnerId);
        }

        $this->newPartnerPassword = '';
        $this->flashMessage = Lang::get('auth::manage_user.password_set', ['name' => $this->partnerUsername]);
    }

    public function regenerateCodes(RegenerateRecoveryCodesAction $regenerate, CurrentUser $currentUser, AccountOwner $owner): void
    {
        if (! $owner->isOwner($currentUser->user())) {
            throw new NotFoundHttpException;
        }

        $this->regeneratedCodes = $regenerate($currentUser->user(), $this->partnerUsername);
        $this->flashMessage = Lang::get('auth::manage_user.codes_regenerated', ['name' => $this->partnerUsername]);
    }

    // The <a download> this replaces is a data: URL, which the Android shell
    // drops with no file and no error — so the codes an owner just regenerated
    // for someone else would have gone nowhere at all.
    public function downloadCodes(ShareSheetExport $shareSheet, RecoveryCodeFormatter $formatter): void
    {
        // Nothing to hand over until regenerateCodes() has run, and the button
        // is only drawn once it has; a crafted call arrives here instead.
        if ($this->regeneratedCodes === []) {
            return;
        }

        $this->flashMessage = $shareSheet->export(
            $formatter->filenameFor($this->partnerUsername),
            $formatter->format($this->regeneratedCodes),
        )->message();
    }

    public function render(ViewFactory $views, ShareSheetExport $shareSheet): View
    {
        $view = $views->make('auth::livewire.manage-user-page', [
            'nativeExport' => $shareSheet->replacesWebViewDownload(),
        ]);

        $view->extends('layouts.app', ['title' => Lang::get('auth::manage_user.page_title', ['name' => $this->partnerUsername])]);

        return $view;
    }
}
