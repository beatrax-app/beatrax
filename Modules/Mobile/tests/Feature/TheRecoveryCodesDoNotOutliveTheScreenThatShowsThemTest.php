<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Internal\Http\Middleware\ForgetsSpentRecoveryCodes;
use Modules\Core\Models\User;
use Modules\Desktop\Internal\Http\Middleware\EnsureDatabaseReady;
use Modules\Mobile\Internal\Http\Controllers\RecoveryCodesExportController;
use Modules\Mobile\Internal\Http\PairingEntryUrl;

uses(RefreshDatabase::class);

const RCO_CODES = ['AAAA-BBBB-CCCC', 'DDDD-EEEE-FFFF'];

// The way off the recovery-codes step is a plain link, so no server call of
// this component's ever runs again. Whatever forgets the codes therefore has
// to be the arrival of the next request, whichever request that turns out to
// be.
function rcoShowTheCodesTo(string $username): void
{
    test()->withoutMiddleware(EnsureDatabaseReady::class);

    $user = User::query()->create([
        'username' => $username,
        'password' => bcrypt('a-genuinely-long-password'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    test()->actingAs($user);
    test()->withSession([RecoveryCodesExportController::SESSION_KEY => RCO_CODES]);

    test()->get(route('mobile.import'))->assertOk()->assertSee(RCO_CODES[0]);

    // The mobile root empties whatever the last request left in the store, so
    // the cookie a WebView sends is the only thing carrying the ceremony into
    // the next request; withCredentials() is what puts it on the fetch() the
    // save button makes. Without both, every request below meets a new session.
    test()->withCredentials()->withCookie((string) config('session.cookie'), (string) session()->getId());
}

it('forgets them once the reader follows the pairing link', function (): void {
    rcoShowTheCodesTo('phone-owner-follows-the-link');

    test()->get(PairingEntryUrl::importing());

    expect(session(RecoveryCodesExportController::SESSION_KEY))
        ->toBeNull('the codes are the only way back into the account, so they must not outlive the one screen that shows them');
});

it('forgets them for a reader who taps nothing and simply goes elsewhere', function (): void {
    rcoShowTheCodesTo('phone-owner-walks-away');

    test()->get(route('mobile.welcome'));

    expect(session(RecoveryCodesExportController::SESSION_KEY))
        ->toBeNull('no tap on this screen is a promise, so leaving it by any route has to end the ceremony');
});

it('still hands them to the share sheet on the request right after the display', function (): void {
    rcoShowTheCodesTo('phone-owner-saves-first');

    expect(test()->getJson(route('mobile.recovery-codes.export'))->getStatusCode())->toBeGreaterThanOrEqual(300);

    expect(session(RecoveryCodesExportController::SESSION_KEY))
        ->toBe(RCO_CODES, 'a failed export must not consume codes shown exactly once');

    test()->get(route('mobile.welcome'));

    expect(session(RecoveryCodesExportController::SESSION_KEY))
        ->toBeNull('saving them does not extend the ceremony past the screen');
});

// The two roots keep separate stacks, and a middleware registered on one of
// them is dead on the other with no error at all. This one is the whole
// mechanism above: absent from the mobile root, the phone would keep its
// recovery codes for the life of the session and nothing would say so.

it('is registered on both application roots, not just the one the suite booted', function (): void {
    $repoRoot = str_ends_with(base_path(), DIRECTORY_SEPARATOR.'mobile-app') ? dirname(base_path()) : base_path();

    foreach (['bootstrap/app.php', 'mobile-app/bootstrap/app.php'] as $bootstrap) {
        $path = $repoRoot.DIRECTORY_SEPARATOR.$bootstrap;

        expect(is_file($path))->toBeTrue($bootstrap.' must exist to be checked');
        expect((string) file_get_contents($path))
            ->toContain(ForgetsSpentRecoveryCodes::class)
            ->toContain('ForgetsSpentRecoveryCodes::class');
    }
});
