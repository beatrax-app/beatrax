<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Http\Middleware;

use Closure;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Http\Request;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Enums\Locale;
use Modules\Core\Public\Services\LocaleNegotiator;
use Symfony\Component\HttpFoundation\Response;

// Binds the request's active locale onto the translator before the route
// renders, so downstream Lang::get lookups and the layout's <html lang>
// read the same language. The three signals are gathered here and handed
// to the negotiator, which owns the precedence rule.
/**
 * @link ../../../../../.docs/features/core/architecture.md
 */
final class SetLocale
{
    public function __construct(
        private readonly Translator $translator,
        private readonly CurrentUser $currentUser,
        private readonly LocaleNegotiator $negotiator,
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

        // Symfony parses Accept-Language and returns the best match from the
        // supported set (DEFAULT-first, so no match yields English rather
        // than the first-declared case).
        $browserLocale = $request->getPreferredLanguage(Locale::codes());

        $this->translator->setLocale(
            $this->negotiator->resolve($userLocale, $sessionLocale, $browserLocale),
        );

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
