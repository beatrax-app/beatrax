<?php

declare(strict_types=1);

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Core\Models\User;
use Modules\Mobile\Internal\Http\Middleware\ClientSideRedirect;
use Symfony\Component\HttpFoundation\Response;

/*
 * A server redirect never moved the address bar inside the Android shell.
 *
 * Every request is served from shouldInterceptRequest(), which can only hand
 * the WebView a body for the URL it was asked for — so PHPWebViewClient
 * follows the 3xx itself and returns the target's HTML under the original
 * path. Measured on device: /login, /signup and /reset-password each returned
 * HTTP 200 with the full dashboard (147093 bytes, "Dashboard · Beatrax") while
 * the address still read /reset-password. The same flattening put the import
 * wizard under "/" on a fresh install.
 *
 * The remedy has to be a document that navigates itself, because nothing the
 * server sends in a header can reach the address bar through that API.
 */

beforeEach(function (): void {
    putenv('NATIVEPHP_PLATFORM=android');
    $_SERVER['NATIVEPHP_PLATFORM'] = 'android';

    $this->redirectUser = User::query()->create([
        'username' => 'redir-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
    ]);

    test()->actingAs($this->redirectUser);
});

afterEach(function (): void {
    putenv('NATIVEPHP_PLATFORM');
    unset($_SERVER['NATIVEPHP_PLATFORM'], $_ENV['NATIVEPHP_PLATFORM']);
});

it('sends a guest route somewhere the address bar can follow', function (): void {
    $response = $this->get('/login', ['Accept' => 'text/html']);

    $response->assertOk();
    expect($response->headers->get('Location'))->toBeNull();
    expect($response->getContent())->toContain('window.location.replace("/")');
});

it('does the same for every guest route', function (): void {
    foreach (['/signup', '/reset-password'] as $path) {
        $response = $this->get($path, ['Accept' => 'text/html']);

        $response->assertOk();
        expect($response->getContent())->toContain('window.location.replace(');
    }
});

it('carries the query string and fragment across', function (): void {
    $middleware = app(ClientSideRedirect::class);

    $response = $middleware->handle(
        Request::create('/login', 'GET', server: ['HTTP_ACCEPT' => 'text/html']),
        static fn (): Response => new RedirectResponse('/budgets?month=2026-08&view=table#totals'),
    );

    // Decoded, because which branch renders depends on whether a CSP nonce was
    // minted, and the meta-refresh branch escapes the ampersand. Asserting only
    // that the target was a same-origin path passed just as well with the query
    // dropped, which is the one thing the name promises.
    expect(html_entity_decode((string) $response->getContent()))
        ->toContain('/budgets?month=2026-08&view=table#totals');
});

it('leaves a Livewire round-trip its redirect', function (): void {
    $response = $this->get('/login', ['Accept' => 'text/html', 'X-Livewire' => 'true']);

    $response->assertRedirect();
});

it('leaves a JSON caller its redirect', function (): void {
    $response = $this->getJson('/login');

    expect($response->getStatusCode())->toBe(302);
});

it('does nothing at all off-device', function (): void {
    putenv('NATIVEPHP_PLATFORM');
    unset($_SERVER['NATIVEPHP_PLATFORM'], $_ENV['NATIVEPHP_PLATFORM']);

    $response = $this->get('/login', ['Accept' => 'text/html']);

    $response->assertRedirect();
});

it('never navigates the shell off this origin', function (): void {
    $middleware = app(ClientSideRedirect::class);

    $rewrite = static fn (string $location): Response => $middleware->handle(
        Request::create('/login', 'GET', server: ['HTTP_ACCEPT' => 'text/html']),
        static fn (): Response => new RedirectResponse($location),
    );

    // A browser normalises the backslash in a URL path to a slash, so
    // "/\evil.example" would reach location.replace() as "//evil.example" —
    // protocol-relative, and off this origin. These are refused outright:
    // the redirect is handed back untouched for the shell to flatten as it
    // always did, which is no worse than before and navigates nowhere new.
    foreach (['/\\evil.example/steal', '\\\\evil.example/steal'] as $location) {
        $response = $rewrite($location);

        expect($response->isRedirection())->toBeTrue('"'.$location.'" was rewritten instead of refused')
            ->and(str_contains((string) $response->getContent(), 'location.replace'))->toBeFalse(
                '"'.$location.'" reached the client as a navigation'
            );
    }

    // A Location naming another host keeps only its path — the same
    // reduction the Android client already performs on every redirect it
    // follows, so the host never reaches the page either way.
    foreach (['https://evil.example/steal', '//evil.example/steal'] as $location) {
        $body = (string) $rewrite($location)->getContent();

        // Asserted on the target rather than on the mechanism: the document
        // navigates with a script when a CSP nonce is available and with a
        // meta refresh when one is not, and both forms are correct.
        expect(str_contains($body, 'evil.example'))->toBeFalse('"'.$location.'" leaked its host')
            ->and(str_contains($body, '/steal'))->toBeTrue('"'.$location.'" did not reduce to its path');
    }
});

it('keeps a same-origin path from a fully-qualified Location', function (): void {
    $middleware = app(ClientSideRedirect::class);

    $response = $middleware->handle(
        Request::create('/login', 'GET', server: ['HTTP_ACCEPT' => 'text/html']),
        static fn (): Response => new RedirectResponse('https://beatrax.test/budgets?month=2026-08'),
    );

    expect((string) $response->getContent())->toContain('/budgets?month=2026-08')
        ->and((string) $response->getContent())->not->toContain('beatrax.test');
});

it('leaves iOS redirects alone, because its shell already follows them', function (): void {
    // iOS serves the app from a php:// custom scheme whose PHPSchemeHandler
    // reads Location, rewrites a rooted path to php://127.0.0.1<path> and
    // performs a real navigation. Rewriting the redirect there would replace
    // a working native mechanism with a weaker JavaScript one.
    putenv('NATIVEPHP_PLATFORM=ios');
    $_SERVER['NATIVEPHP_PLATFORM'] = 'ios';

    $response = $this->get('/login', ['Accept' => 'text/html']);

    $response->assertRedirect();

    putenv('NATIVEPHP_PLATFORM=android');
    $_SERVER['NATIVEPHP_PLATFORM'] = 'android';
});
