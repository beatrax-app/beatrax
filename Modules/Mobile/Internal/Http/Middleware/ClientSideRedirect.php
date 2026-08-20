<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Http\Middleware;

use Closure;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Modules\Core\Public\Services\UserDataPathService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

// A server redirect never moves the address bar inside the ANDROID shell:
// shouldInterceptRequest() can only hand the WebView a body for the URL it
// asked for, so /login rendered the dashboard under /login. iOS is excluded —
// its PHPSchemeHandler already follows Location with a real navigation.
/**
 * @link ../../../../../.docs/features/mobile/architecture.md
 */
final class ClientSideRedirect
{
    public function __construct(private readonly Vite $vite) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // instanceof, not just isRedirection(): StreamedResponse and
        // BinaryFileResponse throw from setContent(), so a 3xx of either class
        // would 500 where it used to redirect.
        if (UserDataPathService::platform() !== 'android' || ! $response instanceof RedirectResponse) {
            return $response;
        }

        if (! $this->isDocumentNavigation($request)) {
            return $response;
        }

        $target = $this->samePathTarget((string) $response->headers->get('Location', ''));
        if ($target === null) {
            return $response;
        }

        // Mutated rather than replaced: the redirect carries the session
        // cookie and any flash headers, and a fresh Response would drop them.
        $response->headers->remove('Location');
        $response->setStatusCode(200);
        $response->headers->set('Content-Type', 'text/html; charset=UTF-8');
        $response->setContent($this->document($target));

        return $response;
    }

    // Only a document navigation may be rewritten: a fetch(), an <img> or a
    // Livewire round-trip expects the redirect itself, and acceptsHtml()
    // answers yes to `*/*` and to a missing Accept alike — which is every
    // sub-resource the shell fetches.

    // The Android WebView is Chromium and names the destination. Where that
    // header is absent, an Accept that literally says text/html stands in: a
    // navigation sends it, an <img> and a default fetch() do not.
    private function isDocumentNavigation(Request $request): bool
    {
        if ($request->expectsJson() || $request->hasHeader('X-Livewire')) {
            return false;
        }

        $destination = $request->headers->get('Sec-Fetch-Dest');

        if (is_string($destination) && $destination !== '') {
            return $destination === 'document';
        }

        return str_contains(mb_strtolower((string) $request->headers->get('Accept', '')), 'text/html');
    }

    // Path, query and fragment only. The shell serves one origin, and a
    // Location naming another host would otherwise navigate the app off
    // itself — the redirect equivalent of an open redirect.
    private function samePathTarget(string $location): ?string
    {
        if ($location === '') {
            return null;
        }

        $parts = parse_url($location);
        if ($parts === false) {
            return null;
        }

        $path = $parts['path'] ?? '/';

        // A browser normalises a backslash to a forward slash in the path of
        // an http(s) URL, so "/\evil.example" reaches location.replace() as
        // "//evil.example" — protocol-relative, and off this origin. Both
        // characters are therefore treated as the same separator here.
        $path = str_replace('\\', '/', $path);
        if (! str_starts_with($path, '/')) {
            $path = '/'.$path;
        }

        // One leading slash exactly: two is protocol-relative, whatever the
        // host component parsed as.
        if (str_starts_with($path, '//')) {
            return null;
        }

        return $path
            .(isset($parts['query']) ? '?'.$parts['query'] : '')
            .(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    }

    // replace(), not assign(): the URL being left behind redirects straight
    // back here, so leaving it in history would make Back a no-op the user
    // has to press twice.
    private function document(string $target): string
    {
        $encoded = (string) json_encode(
            $target,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES,
        );

        $href = htmlspecialchars($target, ENT_QUOTES);
        $nonce = $this->vite->cspNonce();

        // A target carrying bytes json_encode refuses comes back false, and
        // the script branch would emit `window.location.replace();` — a
        // TypeError and a blank page. The meta refresh takes that case too,
        // because it needs no encoding at all.

        // Without a nonce the CSP blocks the script and the reader gets a
        // blank page instead of a redirect, so the no-nonce path navigates
        // with a meta refresh instead. It leaves a history entry the script
        // does not, which is the lesser problem by a long way.
        if ($nonce === null || $encoded === '' || $encoded === 'false') {
            return '<!doctype html><html><head><meta charset="utf-8">'
                ."<meta http-equiv=\"refresh\" content=\"0;url={$href}\">"
                .'<title>Beatrax</title></head><body>'
                ."<a href=\"{$href}\">{$href}</a>"
                .'</body></html>';
        }

        $nonceAttribute = ' nonce="'.htmlspecialchars($nonce, ENT_QUOTES).'"';

        return '<!doctype html><html><head><meta charset="utf-8">'
            .'<title>Beatrax</title>'
            ."<script{$nonceAttribute}>window.location.replace({$encoded});</script>"
            .'</head><body>'
            ."<noscript><a href=\"{$href}\">{$href}</a></noscript>"
            .'</body></html>';
    }
}
