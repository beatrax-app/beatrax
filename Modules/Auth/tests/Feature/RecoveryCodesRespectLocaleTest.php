<?php

declare(strict_types=1);

use Modules\Core\Models\User;

// The screen renders once from session state and 404s without it.
const RECOVERY_CODES_SESSION_KEY = 'auth.signup.recovery_codes_plain';

// Reported rendering in English for a Dutch user. The view is fully
// translated, so a failure here is locale resolution, not a missing string.

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
    // "auto" is stored as null: a user who never opened the language picker,
    // whose start-page choice is still only in the session.
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
