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

// The plaintext codes are never stored on a public component property --
// every method reads them fresh from the session, so the plaintext never
// round-trips to the browser wire snapshot.
final class RecoveryCodesDisplay extends Component
{
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
        $view->extends('layouts.app', ['title' => 'Save these recovery codes · beatrax']);

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
