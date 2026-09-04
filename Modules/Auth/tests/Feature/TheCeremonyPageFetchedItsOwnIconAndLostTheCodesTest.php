<?php

declare(strict_types=1);

use Modules\Auth\Public\Recovery\PendingRecoveryCodes;
use Modules\Core\Models\User;

// The pending codes survive exactly one request past the last one that renewed
// them, and only the ceremony renews. That is the design -- but the ceremony
// page fetches five things on its own behalf: the manifest, the icon, the
// splash, a PWA icon, and the service worker the layout registers on load. Each
// one aged the flash bag, so by the time the reader ticked "I have saved these"
// the codes were gone and the click answered 404 and bounced them onward.
//
// Every existing test drove the component in isolation, where a browser's
// sub-resource fetches do not happen, so all of them passed.

function ceremonyReader(): User
{
    /** @var User $user */
    $user = User::query()->create([
        'username' => 'ceremony-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    return $user;
}

function ceremonyBegun(): void
{
    test()->actingAs(ceremonyReader())->withSession([
        PendingRecoveryCodes::SESSION_KEY => ['ABCD-EFGH-JKLM-NPQR-STUV'],
    ]);

    test()->get('/recovery-codes')->assertOk();
}

it('still shows the codes after the page has fetched one of its own sub-resources', function (string $uri): void {
    ceremonyBegun();

    test()->get($uri)->assertOk();

    test()->get('/recovery-codes')->assertOk();
})->with([
    '/sw.js',
    '/site.webmanifest',
    '/icon.png',
    '/splash.png',
    '/icons/icon-192.png',
]);

it('still shows the codes after the whole set a real page load fetches', function (): void {
    ceremonyBegun();

    foreach (['/site.webmanifest', '/icon.png', '/icons/icon-192.png', '/splash.png', '/sw.js'] as $uri) {
        test()->get($uri)->assertOk();
    }

    test()->get('/recovery-codes')->assertOk();
});

// The control. Without it the rule above passes just as well on a middleware
// that never forgets anything, which is the failure this whole mechanism
// replaced: codes left readable for the rest of the session's life.
it('still forgets them when the reader actually goes somewhere', function (): void {
    ceremonyBegun();

    test()->get('/settings')->assertOk();

    test()->get('/recovery-codes')->assertRedirect(route('setup'));
});
