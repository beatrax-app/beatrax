<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Http\Livewire;

use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Auth\Public\Recovery\RecoveryCodeFormatter;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\Lang;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// The plaintext codes are never stored on a public component property --
// every method reads them fresh from the session, so the plaintext never
// round-trips to the browser wire snapshot.
final class RecoveryCodesDisplay extends Component
{
    private const SESSION_KEY = 'auth.signup.recovery_codes_plain';

    public bool $confirmed = false;

    public function mount(Session $session): void
    {
        // Reaching this page outside the post-signup ceremony has no
        // codes to show — present a 404 rather than an empty page.
        $this->codesFromSession($session);
    }

    public function continueAfterSave(Session $session, UrlGenerator $urls): void
    {
        if (! $this->confirmed) {
            return;
        }

        $session->forget(self::SESSION_KEY);

        $this->redirect($urls->route('dashboard'), navigate: false);
    }

    public function render(
        ViewFactory $views,
        CurrentUser $currentUser,
        Session $session,
        RecoveryCodeFormatter $formatter,
    ): View {
        $codes = $this->codesFromSession($session);
        $username = $currentUser->user()->username;

        // Built in the browser from these, NOT streamed from a Livewire
        // action: a WebView has no download manager for a StreamedResponse.
        // View data keeps the plaintext out of the wire snapshot, the same
        // reason no method here puts codes on a public property.
        $view = $views->make('auth::livewire.recovery-codes-display', [
            'codes' => $codes,
            'username' => $username,
            'downloadFilename' => $formatter->filenameFor($username),
            'downloadPayload' => $formatter->format($codes),
        ]);

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => Lang::get('auth::recovery_codes.page_title')]);

        return $view;
    }

    /**
     * @return list<string>
     */
    private function codesFromSession(Session $session): array
    {
        /** @var list<string>|null $codes */
        $codes = $session->get(self::SESSION_KEY);

        if ($codes === null) {
            throw new NotFoundHttpException;
        }

        return $codes;
    }
}
