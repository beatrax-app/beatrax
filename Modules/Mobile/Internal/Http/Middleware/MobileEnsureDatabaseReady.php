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

/**
 * @link ../../../../../.docs/features/mobile/architecture.md#a-half-built-schema-outranks-every-route-exemption
 */
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

    // The incomplete screen itself, the Livewire round trip its retry button
    // makes, and the artefacts the lock layout renders it inside. Everything
    // else is a screen reading tables that may not be there.
    /** @var array<int, string> */
    private const array REACHABLE_ON_A_HALF_BUILT_SCHEMA = [
        'mobile.database-incomplete',
        'site.webmanifest',
        'pwa.icon',
        'app.icon',
        'app.splash',
        'locale.switch',
    ];

    public function __construct(
        private MobileFirstLaunchBootstrap $bootstrap,
        private UrlGenerator $urls,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Ahead of the exemption list, not behind it: those routes are exempt
        // because they run before a user account exists, which is a different
        // thing from running before the TABLES do. Behind it, the welcome
        // screen opened over thirteen tables of a hundred and two.
        if (SchemaCompletionMarker::isRaised() && ! $this->survivesAHalfBuiltSchema($request)) {
            return new RedirectResponse($this->urls->route('mobile.database-incomplete'));
        }

        // A run that died partway still created `users`, so an empty table
        // reads as "new phone" — which is why the marker above is asked first.
        if (! $this->isExempt($request) && $this->bootstrap->isFreshInstall()) {
            return new RedirectResponse($this->urls->route('mobile.welcome'));
        }

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }

    private function survivesAHalfBuiltSchema(Request $request): bool
    {
        $name = $request->route()?->getName();
        if (! is_string($name)) {
            return false;
        }

        return array_any(
            self::REACHABLE_ON_A_HALF_BUILT_SCHEMA,
            fn (string $route): bool => str_starts_with($name, $route),
        ) || $this->matchesExemptSuffix($name);
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
