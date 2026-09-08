<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Contracts\SystemLanguageSource;
use Modules\Core\Public\Enums\Locale;
use Modules\Core\Public\Services\LocaleNegotiator;
use Symfony\Component\HttpFoundation\Response;

// Binds the request's active locale onto the application before the route
// renders, so downstream Lang::get lookups and the layout's <html lang>
// read the same language. The three signals are gathered here and handed
// to the negotiator, which owns the precedence rule.
final readonly class SetLocale
{
    public function __construct(
        private CurrentUser $currentUser,
        private LocaleNegotiator $negotiator,
        private ?SystemLanguageSource $systemLanguage = null,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // A logged-in user's stored override (null = "auto") wins; a guest
        // on the login/setup surfaces has none, so their session choice or
        // the browser header decides.
        $userLocale = $this->currentUser->isAuthenticated()
            ? $this->currentUser->user()->locale
            : null;

        $sessionLocale = $request->hasSession()
            ? $this->stringOrNull($request->session()->get('locale'))
            : null;

        $this->negotiator->apply($this->negotiator->resolve(
            $userLocale,
            $sessionLocale,
            $this->browserLocale($request),
            $this->deviceLocale(),
        ));

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    // Null when the request named no language at all, which is NOT what
    // getPreferredLanguage answers: given a supported set it returns the first
    // entry rather than null, and that is English. Handing that on as a
    // preference would rank a header nobody sent above the device that has one.
    private function browserLocale(Request $request): ?string
    {
        $header = trim((string) $request->headers->get('Accept-Language', ''));

        // Symfony parses the header and returns the best match from the
        // supported set (DEFAULT-first, so no match yields English rather
        // than the first-declared case).
        return $header === '' ? null : $request->getPreferredLanguage(Locale::codes());
    }

    // The Android WebView sends no Accept-Language at all — measured on a
    // Galaxy A51 whose OS was Dutch while the app rendered English — so on a
    // phone the OS setting is the only signal there is.
    private function deviceLocale(): ?string
    {
        $tag = $this->systemLanguage?->tag();

        return $tag === null ? null : Locale::fromTag($tag);
    }
}
