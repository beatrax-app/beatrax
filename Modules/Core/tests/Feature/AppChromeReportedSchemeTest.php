<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Modules\Core\Models\User;
use Modules\Core\Public\Enums\Theme;
use Modules\Core\Public\Support\AppChromeResolver;
use Modules\Core\Public\Support\PatternScan;

// A `system` theme is decided by prefers-color-scheme, which the server cannot
// read. Left to a client-side script alone the server renders one answer and the
// client computes another moments later, and the page visibly changes theme
// between them — most obviously on the lock screen.

function chromeWithCookie(?string $scheme): AppChromeResolver
{
    $request = Request::create('/lock');

    if ($scheme !== null) {
        $request->cookies->set(AppChromeResolver::SCHEME_COOKIE, $scheme);
    }

    app()->instance(Request::class, $request);

    return app(AppChromeResolver::class);
}

it('renders dark server-side when the client reported dark', function (): void {
    $chrome = chromeWithCookie('dark')->resolve();

    expect($chrome->isDark)->toBeTrue()
        ->and($chrome->needsPrePaintScript)->toBeFalse();
});

it('renders light server-side when the client reported light', function (): void {
    $chrome = chromeWithCookie('light')->resolve();

    expect($chrome->isDark)->toBeFalse()
        ->and($chrome->needsPrePaintScript)->toBeFalse();
});

it('defers to the pre-paint script until the client has reported', function (): void {
    $chrome = chromeWithCookie(null)->resolve();

    expect($chrome->needsPrePaintScript)->toBeTrue();
});

it('ignores a junk value rather than trusting it', function (): void {
    $chrome = chromeWithCookie('sepia')->resolve();

    expect($chrome->needsPrePaintScript)->toBeTrue();
});

// The three cases above hand the resolver a Request built in memory, so the
// cookie never passes the middleware the browser's does. `document.cookie`
// writes plaintext, and the `web` group decrypts every cookie it is not told
// to leave alone — so the reported scheme arrived as null on every real
// request and the shell rendered light on a dark device.
it('reads the scheme the browser actually sends, through the middleware stack', function (): void {
    $user = User::query()->create([
        'username' => 'scheme-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'theme' => Theme::System->value,
    ]);

    $html = (string) test()->actingAs($user)
        ->withUnencryptedCookie(AppChromeResolver::SCHEME_COOKIE, 'dark')
        ->followingRedirects()
        ->get('/')
        ->getContent();

    $matches = PatternScan::first('/<html\b[^>]*\bclass="([^"]*)"/i', $html);

    expect($matches)->toHaveCount(2)
        ->and($matches[1])->toContain('dark')
        ->and($matches[1])->not->toContain('light');
});
