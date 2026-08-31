<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Http\Middleware;

use Closure;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Mobile\Internal\Boot\MobileFirstLaunchBootstrap;
use Modules\Mobile\Internal\Boot\SchemaCompletionMarker;
use Symfony\Component\HttpFoundation\Response;

final readonly class MobileEnsureDatabaseReady
{
    // Every route here runs before any user account exists on this
    // device (the welcome/signup/import/pair/setup chain), or is a public
    // artifact (webmanifest/icon) required before any user exists - see
    // .docs/features/mobile/architecture.md.
    /** @var array<int, string> */
    private const array EXEMPT_ROUTE_PREFIXES = [
        'mobile.welcome',
        'signup',
        'mobile.import',
        // The route back from a wipe. It exists to be reached when there is
        // no user, which is exactly the state this gate redirects out of.
        'mobile.restore',
        'mobile.pair',
        'mobile.setup',
        // Its own destination, and the only screen a half-built schema can
        // render at all.
        'mobile.database-incomplete',
        'setup',
        'site.webmanifest',
        'pwa.icon',
        // Static brand artefacts, served by PHP because no web server sits in
        // front of it here. The desktop setup, staging and welcome shells
        // embed the app mark, and this gate is what shows them.
        'app.icon',
        'app.splash',
        'locale.switch',
    ];

    // The Livewire AJAX update endpoint must reach the signup form's
    // submit() handler on a fresh install.
    /** @var array<int, string> */
    private const array EXEMPT_ROUTE_SUFFIXES = [
        'livewire.update',
        // The restore form's file input posts here. Redirected, it answers
        // with the welcome page at 200 -- iOS turns a redirect into the
        // target's HTML -- and Livewire's JS parses a document as JSON, throws,
        // and never settles the upload. The screen said "Uploading..." forever.
        'livewire.upload-file',
        'livewire.preview-file',
    ];

    public function __construct(
        private MobileFirstLaunchBootstrap $bootstrap,
        private UrlGenerator $urls,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->isExempt($request)) {
            /** @var Response $response */
            $response = $next($request);

            return $response;
        }

        // Ahead of the fresh-install check: a run that died partway still
        // created `users`, so an empty table reads as "new phone" and the
        // welcome screen opens over a schema missing everything after it.
        // A marker, not a question to the migrator — this runs every request.
        if (SchemaCompletionMarker::isRaised()) {
            return new RedirectResponse($this->urls->route('mobile.database-incomplete'));
        }

        if ($this->bootstrap->isFreshInstall()) {
            return new RedirectResponse($this->urls->route('mobile.welcome'));
        }

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }

    private function isExempt(Request $request): bool
    {
        $name = $request->route()?->getName();
        if (! is_string($name)) {
            return false;
        }

        return $this->matchesExemptPrefix($name) || $this->matchesExemptSuffix($name);
    }

    private function matchesExemptPrefix(string $name): bool
    {
        return array_any(self::EXEMPT_ROUTE_PREFIXES, fn (string $prefix): bool => str_starts_with($name, $prefix));
    }

    private function matchesExemptSuffix(string $name): bool
    {
        return array_any(self::EXEMPT_ROUTE_SUFFIXES, fn (string $suffix): bool => str_ends_with($name, $suffix));
    }
}
