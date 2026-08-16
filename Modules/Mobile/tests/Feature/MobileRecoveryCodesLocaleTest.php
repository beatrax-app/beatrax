<?php

declare(strict_types=1);

use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;

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
    // welcome-screen choice is the session.
    // A device with no account yet is redirected on to the welcome gate, but
    // the locale must already be resolved by then — the redirect target is
    // itself a screen the user reads.
    $this->withSession(['locale' => 'nl'])
        ->get(route('mobile.import'))
        ->assertRedirect();

    expect(app('translator')->getLocale())->toBe('nl');
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
