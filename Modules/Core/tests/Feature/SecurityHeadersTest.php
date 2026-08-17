<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Modules\Core\Internal\Http\Middleware\NoStoreFinancialData;
use Modules\Core\Models\User;
use Symfony\Component\HttpFoundation\Response;

uses(RefreshDatabase::class);

/*
 * Baseline response headers on every `web` response.
 *
 * The app renders text it did not write — counterparty names, payment
 * references and receipt bodies all arrive from bank exports and mailboxes,
 * and the transactions list deliberately emits server-built HTML for search
 * highlighting. Escaping is the control; these headers, and the nonce-based
 * Content-Security-Policy, are what stands between a missed escape and a
 * working attack. The policy admits first-party scripts by nonce and keeps
 * 'unsafe-eval' only because Alpine compiles its expressions at runtime.
 */

function securityHeadersUser(): User
{
    return User::query()->create([
        'username' => 'headers-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('irrelevant-for-headers'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

it('sends the baseline security headers on an authenticated page', function (): void {
    $response = test()->actingAs(securityHeadersUser())->get('/settings');

    $response->assertOk();
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('Referrer-Policy', 'no-referrer');
    $response->assertHeader('X-Permitted-Cross-Domain-Policies', 'none');
});

it('sends a nonce-based Content-Security-Policy that backstops a missed escape', function (): void {
    $response = test()->actingAs(securityHeadersUser())->get('/settings');

    $csp = (string) $response->headers->get('Content-Security-Policy');
    expect($csp)->toContain("script-src 'self' 'nonce-")
        ->and($csp)->toContain("object-src 'none'")
        ->and($csp)->toContain("base-uri 'self'")
        ->and($csp)->toContain("frame-ancestors 'none'");

    // frame-ancestors supersedes X-Frame-Options, which is withdrawn to avoid
    // the browsers-disagree conflict when both a CSP and the header are set.
    expect($response->headers->has('X-Frame-Options'))->toBeFalse();
});

it('keeps the no-store cache posture the headers were added alongside', function (): void {
    // Financial figures must not survive in a shared cache or in the back
    // button after sign-out; that guarantee predates the headers above and
    // must not regress when they are edited.
    $response = test()->actingAs(securityHeadersUser())->get('/settings');

    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('does not fight a response that sets its own frame policy', function (): void {
    // The Dev Console embeds Horizon and allows it with
    // `frame-ancestors 'self'`. Browsers disagree on whether CSP or
    // X-Frame-Options wins when both are present, so ours stands down.
    $middleware = new NoStoreFinancialData(app(Vite::class), app());

    $response = $middleware->handle(
        Request::create('/dev/queue'),
        static function (): Response {
            $inner = new Response('ok');
            $inner->headers->set('Content-Security-Policy', "frame-ancestors 'self'");

            return $inner;
        },
    );

    expect($response->headers->has('X-Frame-Options'))->toBeFalse()
        ->and($response->headers->get('Content-Security-Policy'))->toBe("frame-ancestors 'self'")
        // The rest still apply — only the conflicting one is withdrawn.
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});
