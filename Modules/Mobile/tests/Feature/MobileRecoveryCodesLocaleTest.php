<?php

declare(strict_types=1);

use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Mobile\Internal\Http\Middleware\MobileEnsureDatabaseReady;
use Tests\Helpers\LivewireRoundTrip;

/*
 * Mobile does NOT use the Auth module's recovery-codes screen — it has its own,
 * inside MobileImportBootstrap, on `mobile::import.recovery_*` keys. Fixing the
 * Auth one therefore did nothing for the phone, which is why this stayed broken
 * after the first fix looked green.
 *
 * Both screens exist, so both need pinning.
 */

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

it('serves the mobile import surface in the language stored on the user', function (): void {
    // Driven over HTTP, not Livewire::test(): the locale is applied by the
    // SetLocale middleware, and Livewire::test() bypasses the middleware stack
    // entirely — so a component test here would fail for a reason the device
    // never has, and pass for one it does.
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
    // The import path runs BEFORE an account exists, so the only record of the
    // welcome-screen choice is the session. True under either root, which is
    // why the status code is deliberately not asserted here — the two roots
    // answer this request differently and only the locale is common to both.
    $this->withSession(['locale' => 'nl'])->get(route('mobile.import'));

    expect(app('translator')->getLocale())->toBe('nl');
});

it('resolves the locale before the desktop database gate redirects', function (): void {
    // The regression: SetLocale used to run AFTER EnsureDatabaseReady. A
    // redirect short-circuits the rest of the stack, so every screen a fresh
    // install saw before signing up rendered in English whatever it had picked
    // on the welcome page. The redirect TARGET is a screen the user reads, so
    // the language has to be resolved before the gate can send them there.
    //
    // ->group('repo-root-only'): the redirect is the DESKTOP root's gate. The
    // mobile-app root runs MobileEnsureDatabaseReady instead, which exempts
    // `mobile.import` on purpose — a fresh device has to be able to reach the
    // import bootstrap — so there is no redirect there to pin. Ordering on
    // that root is covered by the assertion above.
    $this->withSession(['locale' => 'nl'])
        ->get(route('mobile.import'))
        ->assertRedirect();

    expect(app('translator')->getLocale())->toBe('nl');
})->group('repo-root-only');

it('shows the codes in the chosen language on the round-trip that reveals them', function (): void {
    // The reported bug, end to end. The recovery step is never navigated to:
    // submit() flips `$step` inside a Livewire ACTION, so the screen is
    // rendered by the update endpoint. Livewire re-applies the locale it
    // snapshotted on the previous render there, which is why this one screen
    // came back English while the signup form before it and the pairing page
    // after it were both Dutch.
    //
    // Skipped under the desktop root, whose EnsureDatabaseReady redirects a
    // 0-user device away from mobile.import — and 0 users is a precondition,
    // since SignupAction is first-user-only. Asked of the registered gate
    // rather than of the response, so a genuine redirect regression on the
    // mobile root still fails here instead of quietly skipping.
    $webGroup = app('router')->getMiddlewareGroups()['web'] ?? [];
    if (! in_array(MobileEnsureDatabaseReady::class, $webGroup, true)) {
        test()->markTestSkipped('mobile.import is only reachable on a 0-user device under the mobile-app root.');
    }

    $this->post(route('locale.switch'), ['code' => 'nl']);

    $page = (string) $this->get(route('mobile.import'))->assertOk()->getContent();

    $rendered = LivewireRoundTrip::call($this, $page, 'mobile.import-bootstrap', 'submit', [
        'username' => 'wessel',
        'password' => 'opensesame-long-enough',
        'passwordConfirmation' => 'opensesame-long-enough',
        'pin' => '1234',
        'confirmPin' => '1234',
    ]);

    expect($rendered)
        ->toContain((include base_path('Modules/Mobile/Resources/lang/nl/import.php'))['recovery_heading'])
        ->not->toContain((include base_path('Modules/Mobile/Resources/lang/en/import.php'))['recovery_heading']);
});

it('renders the recovery heading from the mobile keys, not the auth ones', function (): void {
    // Mobile has its OWN recovery screen on mobile::import.recovery_*. Fixing
    // the Auth module's screen did nothing for the phone — this pins that the
    // mobile keys are the translated ones actually in use.
    app()->setLocale('nl');

    expect(Lang::get('mobile::import.recovery_heading'))->toBe('Bewaar deze herstelcodes')
        ->and(Lang::get('mobile::import.recovery_body'))->not->toBe(
            (include base_path('Modules/Mobile/Resources/lang/en/import.php'))['recovery_body']
        );
});
