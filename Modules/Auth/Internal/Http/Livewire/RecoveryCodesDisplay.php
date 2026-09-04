<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Http\Livewire;

use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Router;
use Livewire\Component;
use Modules\Auth\Public\Recovery\PendingRecoveryCodes;
use Modules\Auth\Public\Recovery\RecoveryCodeFormatter;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Navigation\Destination;
use Modules\Core\Public\Support\Lang;
use Modules\Mobile\Public\Services\ShareSheetExport;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// Read fresh from the session every time, never held on a public property, so
// the plaintext never reaches the wire snapshot.
final class RecoveryCodesDisplay extends Component
{
    public const string SESSION_KEY = PendingRecoveryCodes::SESSION_KEY;

    // Signup and Settings both reach this ceremony, and finishing it must
    // return to whichever asked.
    public const string SESSION_RETURN_KEY = 'auth.recovery_codes.return_to';

    // A token, never a URL: a session value used as a redirect target is an
    // open redirect the moment anything can write it.
    public const string RETURN_TO_SETTINGS = 'settings';

    // A partner reaching this from their forced first password change has no
    // wizard waiting and no settings screen they came from.
    public const string RETURN_TO_DASHBOARD = 'dashboard';

    public bool $confirmed = false;

    public function mount(Session $session, CurrentUser $currentUser, UrlGenerator $urls): mixed
    {
        // Arriving outside the ceremony has no codes to show, but a reader who
        // already finished it is not lost, only behind: signup leaves this page
        // one history entry behind the wizard, whose nine steps share a URL, so
        // the system back button on step one landed here and met a 404.
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
        ShareSheetExport $shareSheet,
    ): View {
        $codes = $this->codesFromSession($session);
        $username = $currentUser->user()->username;

        PendingRecoveryCodes::renew($session);

        // The endpoint keeps a copy inside the app's private container, which
        // is the best a shell that drops WebView downloads can do. A shell that
        // saves them hands the file to the reader instead, so it must not be
        // diverted here. An unmodelled platform keeps the copy.
        $exportRoute = 'mobile.recovery-codes.export';
        $nativeExport = $router->has($exportRoute) && $shareSheet->replacesWebViewDownload();

        $view = $views->make('auth::livewire.recovery-codes-display', [
            'codes' => $codes,
            'downloadFilename' => $formatter->filenameFor($username),
            'downloadSlug' => $formatter->usernameSlug($username),
            'downloadPayload' => $formatter->format($codes),
            'exportUrl' => $nativeExport ? $urls->route($exportRoute) : null,
        ]);

        $view->extends('layouts.app', ['title' => Lang::get('auth::recovery_codes.page_title')]);

        return $view;
    }

    // The default is the setup wizard, not the dashboard: this screen sits
    // between signup and setup, and onboarding has not run yet.
    private function onwardFrom(Session $session, UrlGenerator $urls): string
    {
        return match ($session->pull(self::SESSION_RETURN_KEY)) {
            self::RETURN_TO_SETTINGS => Destination::Settings->urlFrom($urls),
            self::RETURN_TO_DASHBOARD => Destination::Dashboard->urlFrom($urls),
            default => $urls->route('setup'),
        };
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
