<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Http\Livewire;

use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Router;
use Livewire\Component;
use Modules\Auth\Public\Recovery\RecoveryCodeFormatter;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Navigation\Destination;
use Modules\Core\Public\Services\UserDataPathService;
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

    public function mount(Session $session, CurrentUser $currentUser, UrlGenerator $urls): mixed
    {
        // Arriving outside the ceremony has no codes to show, and an empty page
        // would be worse than a 404 — but a reader who has already finished it
        // is not lost, they are behind. Signup leaves this page in history one
        // entry behind the wizard, whose nine steps share a URL, so the system
        // back button on the first step of setup landed here and met the 404.
        if ($session->get(self::SESSION_KEY) === null && $currentUser->isAuthenticated()) {
            return $this->redirect($this->onwardFrom($session, $urls), navigate: false);
        }

        $this->codesFromSession($session);

        return null;
    }

    public function continueAfterSave(Session $session, UrlGenerator $urls): void
    {
        if (! $this->confirmed) {
            return;
        }

        $session->forget(self::SESSION_KEY);

        $this->redirect($this->onwardFrom($session, $urls), navigate: false);
    }

    public function render(
        ViewFactory $views,
        CurrentUser $currentUser,
        Session $session,
        RecoveryCodeFormatter $formatter,
        Router $router,
        UrlGenerator $urls,
    ): View {
        $codes = $this->codesFromSession($session);
        $username = $currentUser->user()->username;

        // The endpoint keeps a copy inside the app's private container, which
        // is the best a shell that drops WebView downloads can do. A shell that
        // saves them hands the file to the reader instead, so it must not be
        // diverted here. An unmodelled platform keeps the copy.
        $exportRoute = 'mobile.recovery-codes.export';
        $nativeExport = UserDataPathService::isMobileRuntime()
            && $router->has($exportRoute)
            && UserDataPathService::platform()?->savesWebViewDownloads() !== true;

        $view = $views->make('auth::livewire.recovery-codes-display', [
            'codes' => $codes,
            'downloadFilename' => $formatter->filenameFor($username),
            'downloadSlug' => $formatter->usernameSlug($username),
            'downloadPayload' => $formatter->format($codes),
            'exportUrl' => $nativeExport ? $urls->route($exportRoute) : null,
        ]);

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => Lang::get('auth::recovery_codes.page_title')]);

        return $view;
    }

    // The default is the setup wizard, not the dashboard: this screen sits
    // between signup and setup, and onboarding has not run yet.
    private function onwardFrom(Session $session, UrlGenerator $urls): string
    {
        return $session->pull(self::SESSION_RETURN_KEY) === self::RETURN_TO_SETTINGS
            ? Destination::Settings->urlFrom($urls)
            : $urls->route('setup');
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
