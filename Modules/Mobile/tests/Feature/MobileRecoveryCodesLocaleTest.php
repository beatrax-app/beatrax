<?php

declare(strict_types=1);

use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Mobile\Internal\Http\Middleware\MobileEnsureDatabaseReady;
use Tests\Helpers\LivewireRoundTrip;

const MOBILE_RECOVERY_SESSION_KEY = 'auth.signup.recovery_codes_plain';

function mobileRecoveryUser(?string $locale): User
{
    return User::query()->create([
        'username' => 'wessel',
        'password' => bcrypt('opensesame-long-enough'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'locale' => $locale,
    ]);
}

// Mobile does not use the Auth module's recovery-codes screen: it has its own
// inside MobileImportBootstrap, on mobile::import.recovery_* keys. Fixing the Auth
// one did nothing for the phone, which is why this stayed broken after the first
// fix looked green.

it('serves the mobile import surface in the language stored on the user', function (): void {
    // Driven over HTTP rather than Livewire::test(): the locale comes from the
    // SetLocale middleware, and Livewire::test() bypasses the middleware stack, so
    // a component test would fail for a reason the device never has.
    $user = mobileRecoveryUser('nl');

    $html = $this->actingAs($user)
        ->withSession([MOBILE_RECOVERY_SESSION_KEY => ['AAAA-1111', 'BBBB-2222']])
        ->get(route('mobile.import'))
        ->assertOk()
        ->getContent();

    expect($html)->toBeString()
        ->and(app('translator')->getLocale())->toBe('nl');
});

it('applies the guest session language on the pre-account import surface', function (): void {
    // The import path runs before an account exists, so the only record of the
    // welcome-screen choice is the session. The status code is deliberately not
    // asserted: the two roots answer this request differently.
    $this->withSession(['locale' => 'nl'])->get(route('mobile.import'));

    expect(app('translator')->getLocale())->toBe('nl');
});

it('resolves the locale before the desktop database gate redirects', function (): void {
    // SetLocale used to run after EnsureDatabaseReady, and a redirect
    // short-circuits the rest of the stack, so every screen a fresh install saw
    // before signing up rendered in English. The gate is the desktop root's; the
    // mobile root exempts mobile.import, so there is no redirect there to pin.
    $this->withSession(['locale' => 'nl'])
        ->get(route('mobile.import'))
        ->assertRedirect();

    expect(app('translator')->getLocale())->toBe('nl');
})->group('repo-root-only');

it('shows the codes in the chosen language on the round-trip that reveals them', function (): void {
    // The recovery step is never navigated to: submit() flips $step inside a
    // Livewire action, so the update endpoint renders it and Livewire re-applies
    // the locale it snapshotted on the previous render. The skip asks the
    // registered gate rather than the response, so a real regression still fails.
    $webGroup = app('router')->getMiddlewareGroups()['web'] ?? [];
    if (! in_array(MobileEnsureDatabaseReady::class, $webGroup, true)) {
        test()->markTestSkipped('mobile.import is only reachable on a 0-user device under the mobile-app root.');
    }

    $this->post(route('locale.switch'), ['code' => 'nl']);

    // The test client keeps no cookie jar, and the mobile root drops the
    // session the previous request left in memory, so the id the switch saved
    // under is carried by hand here the way the webview carries it on device.
    $this->withCookie((string) config('session.cookie'), app('session')->getId());

    $page = (string) $this->get(route('mobile.import'))->assertOk()->getContent();

    $rendered = LivewireRoundTrip::call($this, $page, 'mobile.import-bootstrap', 'submit', [
        'username' => 'wessel',
        'password' => 'opensesame-long-enough',
        'passwordConfirmation' => 'opensesame-long-enough',
        'pin' => '123456',
        'confirmPin' => '123456',
    ]);

    expect($rendered)
        ->toContain((include base_path('Modules/Mobile/Resources/lang/nl/import.php'))['recovery_heading'])
        ->not->toContain((include base_path('Modules/Mobile/Resources/lang/en/import.php'))['recovery_heading']);
});

it('renders the recovery heading from the mobile keys, not the auth ones', function (): void {
    app()->setLocale('nl');

    expect(Lang::get('mobile::import.recovery_heading'))->toBe('Bewaar deze herstelcodes')
        ->and(Lang::get('mobile::import.recovery_body'))->not->toBe(
            (include base_path('Modules/Mobile/Resources/lang/en/import.php'))['recovery_body']
        );
});
