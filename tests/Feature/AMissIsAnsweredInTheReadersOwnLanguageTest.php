<?php

declare(strict_types=1);

use Illuminate\Contracts\Translation\Translator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;

uses(RefreshDatabase::class);

// A URI nothing matched never entered the middleware group, so there was no
// session by the time the error view rendered -- and that view resolves its
// language and theme from exactly there. The copy was never the gap: on one
// session a miss raised INSIDE a route answered in Slovenian while a miss on
// an unmatched URI answered in English.

function missReader(string $locale): User
{
    return User::query()->create([
        'username' => 'miss-reader',
        'password' => 'test-password',
        'is_developer' => false,
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'locale' => $locale,
    ]);
}

it('answers a miss on an unmatched URI in the language the reader chose', function (): void {
    $response = test()->actingAs(missReader('sl'))->get('/no-route-answers-this');

    $response->assertNotFound();

    // Read per-locale, which the repo's own Lang helper cannot do: it answers
    // from the container's current locale and nothing else.
    $translator = app(Translator::class);
    $slovenian = (string) $translator->get('core::errors.404.title', [], 'sl');
    $english = (string) $translator->get('core::errors.404.title', [], 'en');

    // The two must differ, or this asserts nothing at all.
    expect($slovenian)->not->toBe($english);

    expect((string) $response->getContent())
        ->toContain($slovenian)
        ->not->toContain($english)
        ->toContain('lang="sl"');
});

// The control for the case above: this path always worked, because the route it
// aborts from carries the middleware group. If it ever stops matching, the test
// above is measuring something other than the fallback.
it('still answers a miss raised inside a matched route in that same language', function (): void {
    $response = test()->actingAs(missReader('sl'))->get('/icons/not-a-real-icon.png');

    $response->assertNotFound();

    expect((string) $response->getContent())
        ->toContain((string) app(Translator::class)->get('core::errors.404.title', [], 'sl'))
        ->toContain('lang="sl"');
});

// The theme comes from the same resolver and was absent for the same reason,
// and it is deliberately NOT asserted here. `actingAs()` binds the user in the
// container rather than through the session, so `CurrentUser` answers even on a
// request that never entered the middleware group -- the theme resolves with or
// without the fallback and such a test passes either way. Watched it do exactly
// that. The locale is the half that can tell the two apart, because it comes
// from the translator the SetLocale middleware sets. The theme half was
// measured on a served instance instead: a reader on `theme=dark` got a white
// 404, and the same reader gets a dark one now.

// Content negotiation is not given up to gain the language: a client that asked
// for JSON is answered with JSON, which is what `abort(404)` preserves and a
// rendered view would not.
it('still answers JSON to a client that asked for it', function (): void {
    $response = test()->actingAs(missReader('en'))->getJson('/no-route-answers-this');

    $response->assertNotFound();

    expect($response->headers->get('content-type'))->toContain('application/json');
});

// Joining the group is not free: it carries the gate that redirects a device
// with no account yet, and under the fallback that turned every unknown URI
// into a 302. A miss is a miss whoever is asking, and on an install that has
// not been set up there is nobody to send to a login screen anyway.
it('answers a miss on an install with no account at all, rather than redirecting', function (): void {
    expect(User::query()->count())->toBe(0);

    test()->get('/no-route-answers-this')->assertNotFound();
    test()->get('/icons/../.env')->assertNotFound();
});
