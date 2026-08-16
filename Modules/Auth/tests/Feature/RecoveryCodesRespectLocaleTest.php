<?php

declare(strict_types=1);

use Modules\Core\Models\User;

// The screen renders once from session state and 404s without it.
const RECOVERY_CODES_SESSION_KEY = 'auth.signup.recovery_codes_plain';

/*
 * The recovery-codes screen is shown once, immediately after signup, and it is
 * the one page a user is told to save. It was reported rendering in English
 * for a Dutch user — which would mean the locale chosen on the start page does
 * not survive into the screen that matters most.
 *
 * The view itself is fully translated, so any failure here is locale
 * resolution rather than a missing string.
 */

function recoveryCodesUser(string $username, ?string $locale): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('opensesame'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'locale' => $locale,
    ]);
}

it('renders the recovery codes screen in the locale stored on the user', function (): void {
    $user = recoveryCodesUser('recovery-nl', 'nl');

    $this->actingAs($user)
        ->withSession([RECOVERY_CODES_SESSION_KEY => ['AAAA-1111', 'BBBB-2222']])
        ->get(route('auth.recovery-codes-display'))
        ->assertOk()
        ->assertSee('Bewaar deze herstelcodes')
        ->assertDontSee('Save these recovery codes');
});

it('falls back to the session locale when the user has no explicit choice', function (): void {
    // "auto" is stored as null, which is what a user who never opened the
    // language picker looks like — the start-page choice lives in the session
    // at that point, and it still has to reach this screen.
    $user = recoveryCodesUser('recovery-session', null);

    $this->actingAs($user)
        ->withSession(['locale' => 'nl', RECOVERY_CODES_SESSION_KEY => ['AAAA-1111', 'BBBB-2222']])
        ->get(route('auth.recovery-codes-display'))
        ->assertOk()
        ->assertSee('Bewaar deze herstelcodes');
});

it('still renders English for an English user', function (): void {
    $user = recoveryCodesUser('recovery-en', 'en');

    $this->actingAs($user)
        ->withSession([RECOVERY_CODES_SESSION_KEY => ['AAAA-1111', 'BBBB-2222']])
        ->get(route('auth.recovery-codes-display'))
        ->assertOk()
        ->assertSee('Save these recovery codes');
});
