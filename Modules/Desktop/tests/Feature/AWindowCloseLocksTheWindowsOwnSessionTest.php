<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Route;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Desktop\Internal\Listeners\LockOnWindowHideOrClose;
use Native\Desktop\Events\Windows\WindowClosed;

// F1-R18. The sibling test drives the listener directly and so cannot see the
// question this one asks: the shell does not call the listener, it posts an
// event to a route, and what that route resolves as "the session" is the whole
// requirement.
it('reaches the listener through a route that starts a session', function (): void {
    $route = collect(Route::getRoutes()->getRoutes())
        ->first(static fn ($r): bool => $r->uri() === '_native/api/events');

    expect($route)->not->toBeNull();

    expect($route->gatherMiddleware())->toContain(
        Illuminate\Session\Middleware\StartSession::class,
        'The shell delivers WindowClosed by posting to this route. Without '.
        'StartSession the listener resolves a Session that was never loaded '.
        'from the request cookie, so it locks a store the window never reads '.
        'and the lock-on-close guarantee silently does not hold.',
    );
});

it('locks the session the shell posted from, over the real request', function (): void {
    /** @var Session $session */
    $session = $this->app->make(Session::class);

    $dataKey = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    AppLockTestHarness::unlock($session, $dataKey);
    $session->save();

    $unlockedId = $session->getId();
    expect(AppLockTestHarness::isLocked($session))->toBeFalse();

    $this->withSession([])
        ->post('_native/api/events', [
            'event' => WindowClosed::class,
            'payload' => ['main'],
        ])
        ->assertOk();

    $after = $this->app->make(Session::class);
    $after->setId($unlockedId);
    $after->start();

    expect(AppLockTestHarness::isLocked($after))->toBeTrue(
        'The shell posts WindowClosed to _native/api/events carrying the '.
        'window cookie. The session that comes back unlocked is the one the '.
        'window still holds, so closing it locked nothing a reader can see.',
    );

    sodium_memzero($dataKey);
});
