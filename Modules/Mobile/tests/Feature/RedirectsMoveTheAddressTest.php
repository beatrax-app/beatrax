<?php

declare(strict_types=1);

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Core\Models\User;
use Modules\Core\Public\Enums\MobilePlatform;
use Modules\Mobile\Internal\Http\Middleware\ClientSideRedirect;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function (): void {
    putenv('NATIVEPHP_PLATFORM='.MobilePlatform::Android->value);
    $_SERVER['NATIVEPHP_PLATFORM'] = MobilePlatform::Android->value;

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

// A server redirect never moved the address bar inside the Android shell. Every
// request is served from shouldInterceptRequest(), which can only hand the WebView
// a body for the URL it was asked for, so the client follows the 3xx itself and
// returns the target's HTML under the original path.

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
    // minted and the meta-refresh branch escapes the ampersand. Asserting only a
    // same-origin path passed just as well with the query dropped.
    expect(html_entity_decode((string) $response->getContent()))
        ->toContain('/budgets?month=2026-08&view=table#totals');
});

// A GET carrying X-Livewire is not a round-trip: Livewire's client sends that
// header only on the JSON POST to its update endpoint, and wire:navigate names
// itself separately. On a persistent worker a GET wearing it is a leftover,
// which is why the runtime strips it before this middleware ever looks.
it('leaves a Livewire navigation its redirect', function (): void {
    $response = $this->get('/login', ['Accept' => 'text/html', 'X-Livewire-Navigate' => '1']);

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
    // "/\evil.example" would reach location.replace() as "//evil.example":
    // protocol-relative, and off this origin. These are handed back untouched for
    // the shell to flatten as it always did.
    foreach (['/\\evil.example/steal', '\\\\evil.example/steal'] as $location) {
        $response = $rewrite($location);

        expect($response->isRedirection())->toBeTrue('"'.$location.'" was rewritten instead of refused')
            ->and(str_contains((string) $response->getContent(), 'location.replace'))->toBeFalse(
                '"'.$location.'" reached the client as a navigation'
            );
    }

    // A Location naming another host keeps only its path, the same reduction the
    // Android client already performs on every redirect it follows.
    foreach (['https://evil.example/steal', '//evil.example/steal'] as $location) {
        $body = (string) $rewrite($location)->getContent();

        // Asserted on the target rather than the mechanism: the document navigates
        // with a script when a CSP nonce is available and a meta refresh when not.
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
    // iOS serves the app from a php:// custom scheme whose handler reads Location,
    // rewrites a rooted path to php://127.0.0.1<path> and performs a real
    // navigation. Rewriting there would trade it for a weaker JavaScript one.
    putenv('NATIVEPHP_PLATFORM='.MobilePlatform::Ios->value);
    $_SERVER['NATIVEPHP_PLATFORM'] = MobilePlatform::Ios->value;

    $response = $this->get('/login', ['Accept' => 'text/html']);

    $response->assertRedirect();

    putenv('NATIVEPHP_PLATFORM='.MobilePlatform::Android->value);
    $_SERVER['NATIVEPHP_PLATFORM'] = MobilePlatform::Android->value;
});
