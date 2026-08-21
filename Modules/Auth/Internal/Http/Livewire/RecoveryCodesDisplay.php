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

// Read fresh from the session every time, never held on a public property, so
// the plaintext never reaches the wire snapshot.
final class RecoveryCodesDisplay extends Component
{
    public const SESSION_KEY = 'auth.signup.recovery_codes_plain';

    // Signup and Settings both reach this ceremony, and finishing it must
    // return to whichever asked.
    public const SESSION_RETURN_KEY = 'auth.recovery_codes.return_to';

    // A token, never a URL: a session value used as a redirect target is an
    // open redirect the moment anything can write it.
    public const RETURN_TO_SETTINGS = 'settings';

    public bool $confirmed = false;

    public function mount(Session $session): void
    {
        // Arriving outside the ceremony has no codes to show, and an empty page
        // would be worse than a 404.
        $this->codesFromSession($session);
    }

    public function continueAfterSave(Session $session, UrlGenerator $urls): void
    {
        if (! $this->confirmed) {
            return;
        }

        $returnTo = $session->pull(self::SESSION_RETURN_KEY);
        $session->forget(self::SESSION_KEY);

        // The default is the setup wizard, not the dashboard: this screen sits
        // between signup and setup, and onboarding has not run yet.
        $target = $returnTo === self::RETURN_TO_SETTINGS
            ? $urls->route('settings')
            : $urls->route('setup');

        $this->redirect($target, navigate: false);
    }

    public function render(
        ViewFactory $views,
        CurrentUser $currentUser,
        Session $session,
        RecoveryCodeFormatter $formatter,
    ): View {
        $codes = $this->codesFromSession($session);
        $username = $currentUser->user()->username;

        // Built in the browser, not streamed from a Livewire action: a WebView
        // has no download manager for a StreamedResponse.
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
