<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Http\Livewire;

use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Auth\Public\Actions\RegenerateRecoveryCodesAction;
use Modules\Core\Public\Contracts\CurrentUser;

// Codes are stored hashed and shown once, so the only honest way back is to
// mint a fresh set. Like RecoveryCodesDisplay, the plaintext goes through the
// session rather than a public property, never the browser wire snapshot.
final class RecoveryCodesSection extends Component
{
    public function regenerate(
        RegenerateRecoveryCodesAction $regenerate,
        CurrentUser $currentUser,
        Session $session,
        UrlGenerator $urls,
    ): void {
        $user = $currentUser->user();

        // No developer role needed: the action admits a caller acting on
        // themselves, and refuses anyone else with a 404 rather than a 403.
        $codes = $regenerate($user, $user->username);

        $session->put(RecoveryCodesDisplay::SESSION_KEY, $codes);
        $session->put(RecoveryCodesDisplay::SESSION_RETURN_KEY, RecoveryCodesDisplay::RETURN_TO_SETTINGS);

        $this->redirect($urls->route('auth.recovery-codes-display'), navigate: false);
    }

    public function render(ViewFactory $views): View
    {
        return $views->make('auth::livewire.recovery-codes-section');
    }
}
