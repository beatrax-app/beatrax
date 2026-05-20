<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Http\Livewire;

use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Auth\Internal\Recovery\RecoveryCodeFormatter;
use Modules\Core\Public\Contracts\CurrentUser;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The `/recovery-codes` route landing — the one-time recovery-code
 * display ceremony shown immediately after signup.
 *
 * The plaintext codes are read from the session key the SignupAction
 * stashed; reaching the page without that key (a back-navigation or a
 * direct visit) yields a 404. The codes are never stored on a public
 * component property — every method reads them fresh from the session,
 * so the plaintext never round-trips to the browser wire snapshot. The
 * page offers a `.txt` download and gates its Continue button on a
 * checkbox; completing the ceremony forgets the session key so the codes
 * can never be shown again.
 *
 * Constructor-free Livewire component; service collaborators arrive as
 * parameters on the lifecycle / action methods and render().
 */
final class RecoveryCodesDisplay extends Component
{
    /** Session key under which SignupAction stashes the plaintext codes. */
    private const SESSION_KEY = 'auth.signup.recovery_codes_plain';

    public bool $confirmed = false;

    public bool $downloadShown = false;

    public function mount(Session $session): void
    {
        // Reaching this page outside the post-signup ceremony has no
        // codes to show — present a 404 rather than an empty page.
        $this->codesFromSession($session);
    }

    public function download(RecoveryCodeFormatter $formatter, CurrentUser $currentUser, ResponseFactory $responses, Session $session): StreamedResponse
    {
        $this->downloadShown = true;

        $codes = $this->codesFromSession($session);
        $filename = $formatter->filenameFor($currentUser->user()->username);

        return $responses->streamDownload(
            static function () use ($formatter, $codes): void {
                echo $formatter->format($codes);
            },
            $filename,
            ['Content-Type' => 'text/plain; charset=UTF-8'],
        );
    }

    public function continueAfterSave(Session $session, UrlGenerator $urls): void
    {
        if (! $this->confirmed) {
            return;
        }

        $session->forget(self::SESSION_KEY);

        $this->redirect($urls->route('dashboard'), navigate: false);
    }

    public function render(ViewFactory $views, CurrentUser $currentUser, Session $session): View
    {
        $view = $views->make('auth::livewire.recovery-codes-display', [
            'codes' => $this->codesFromSession($session),
            'username' => $currentUser->user()->username,
        ]);

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => 'Save these recovery codes · diederik']);

        return $view;
    }

    /**
     * Reads the stashed plaintext codes from the session, or throws a
     * 404 when the ceremony key is absent.
     *
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
