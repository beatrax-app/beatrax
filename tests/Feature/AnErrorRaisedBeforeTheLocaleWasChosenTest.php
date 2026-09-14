<?php

declare(strict_types=1);

use Illuminate\Contracts\Translation\Translator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;

uses(RefreshDatabase::class);

// A method mismatch is raised while the router is still matching, so it cannot
// reach the fallback route that puts an unmatched URI back inside the group:
// the router answers OPTIONS and throws for everything else. The error view
// therefore rendered in English whatever the reader had asked for.

function beforeLocaleReader(?string $locale): User
{
    return User::query()->create([
        'username' => 'before-locale-'.($locale ?? 'auto'),
        'password' => 'test-password',
        'is_developer' => false,
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'locale' => $locale,
    ]);
}

function beforeLocaleLang(string $body): ?string
{
    return preg_match('/<html[^>]*lang="([^"]*)"/', $body, $match) === 1 ? $match[1] : null;
}

it('answers a method mismatch in the language the request asked for', function (): void {
    // /icon.png is GET-only, so this is refused during routing rather than by
    // anything the group would have run.
    $response = test()
        ->withHeaders(['Accept-Language' => 'sl,en;q=0.5'])
        ->post('/icon.png');

    expect($response->getStatusCode())->toBe(405);

    $translator = app(Translator::class);
    $slovenian = (string) $translator->get('core::errors.4xx.title', [], 'sl');
    $english = (string) $translator->get('core::errors.4xx.title', [], 'en');

    // The two must differ, or this asserts nothing at all.
    expect($slovenian)->not->toBe($english);

    $body = (string) $response->getContent();

    expect(beforeLocaleLang($body))->toBe('sl')
        ->and($body)->toContain($slovenian)
        ->and($body)->not->toContain($english);
});

// The global pass runs before there is a session or a user to read, so it
// decides on the header alone. The group's pass runs later with both, and must
// still win -- otherwise this fix would have quietly overridden every stored
// preference with whatever the browser happened to send.
it('still lets a stored preference beat the header it was asked with', function (): void {
    $body = (string) test()
        ->actingAs(beforeLocaleReader('nl'))
        ->withHeaders(['Accept-Language' => 'sl,en;q=0.5'])
        ->followingRedirects()
        ->get('/')
        ->getContent();

    expect(beforeLocaleLang($body))->toBe('nl');
});

it('still falls to the header for a reader who stored no preference', function (): void {
    $body = (string) test()
        ->actingAs(beforeLocaleReader(null))
        ->withHeaders(['Accept-Language' => 'sl,en;q=0.5'])
        ->followingRedirects()
        ->get('/')
        ->getContent();

    expect(beforeLocaleLang($body))->toBe('sl');
});
