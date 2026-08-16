<?php

declare(strict_types=1);

use Modules\Core\Models\User;
use Tests\Helpers\LivewireRoundTrip;

/*
 * Laravel keeps the active language in TWO places — `config('app.locale')`,
 * which `app()->getLocale()` reads, and the translator's own copy — and
 * SetLocale used to retarget only the second. Livewire reads the first:
 * SupportLocales stores `app()->getLocale()` in the wire snapshot on
 * dehydrate and re-applies it with `app()->setLocale()` on hydrate, which
 * runs AFTER the middleware. So the stale English half was replayed over the
 * negotiated one on every Livewire ACTION, and any screen reached by an
 * action rather than a page load rendered in English while the pages either
 * side of it were correct.
 */

// Both roots' first-launch gates redirect a 0-user install to their own
// welcome screen, which would mask what these tests are asserting.
function localeSnapshotUser(): User
{
    return User::query()->create([
        'username' => 'locale-snapshot-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('locale-snapshot-fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'locale' => null,
    ]);
}

it('renders a livewire action in the negotiated language, not just page loads', function (): void {
    localeSnapshotUser();

    $this->post(route('locale.switch'), ['code' => 'nl']);

    $page = (string) $this->get(route('login'))
        ->assertOk()
        ->assertSee('Inloggen', false)
        ->getContent();

    // The failure copy is built inside the action, so it is translated at the
    // exact moment the replayed snapshot used to have reset the locale.
    $rendered = LivewireRoundTrip::call($this, $page, 'auth.login-page', 'submit', [
        'username' => 'nobody-at-all',
        'password' => 'definitely-not-the-password',
    ]);

    expect($rendered)->toContain('Gebruikersnaam of wachtwoord is onjuist.');
});

it('agrees with itself about the active language', function (): void {
    $user = localeSnapshotUser();

    // The two halves must not drift: `app()->getLocale()` is what Livewire
    // snapshots, and anything it disagrees with the translator about comes
    // back on the next action.
    $this->actingAs($user)
        ->withSession(['locale' => 'nl'])
        ->get(route('settings'))
        ->assertOk();

    expect(app()->getLocale())
        ->toBe('nl')
        ->and(app('translator')->getLocale())->toBe('nl');
});
