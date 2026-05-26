<?php

declare(strict_types=1);

use Modules\Core\Models\User;

/*
 * /dev/artisan whole-page rendering regression guard.
 *
 * Locks two visible contracts for the SAFE-tier artisan-runner page:
 *
 *   1. The page renders a non-blank body for an authenticated
 *      developer — the H1 "Artisan runner" is visible (proving the
 *      Livewire component mounted and the dev-shell layout wrapped
 *      it without erroring into a blank response).
 *
 *   2. The page does NOT emit `style="background: var(...)"` on any
 *      element. The inline-CSS-variable approach renders transparent
 *      in the bundled NativePHP runtime even when `<html class="dark">`
 *      is set, producing the white-panel class of bug. Explicit
 *      Tailwind dark-variant utilities (`bg-white dark:bg-[#0b1220]`)
 *      survive that runtime; this test prevents a regression to the
 *      fragile inline-style path.
 *
 *   3. The page is information-hidden from non-developers: a logged-in
 *      non-developer receives 404, not 403, so the route's existence
 *      is never disclosed (matching the EnsureDeveloperMode contract).
 */

function notWhiteUser(bool $isDeveloper, string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => $isDeveloper,
    ]);
}

it('renders /dev/artisan with HTTP 200 and a non-blank body for a developer user', function (): void {
    $user = notWhiteUser(true, 'not-white-dev');

    $response = $this->actingAs($user)->get('/dev/artisan');

    $response->assertStatus(200);
    // The page H1 — proves the Livewire component mounted into the
    // dev-shell layout. A literally white page would not contain this.
    $response->assertSee('Artisan runner');
    // Body must not be empty.
    expect(trim((string) $response->getContent()))->not->toBe('');
})->group('phase-16.1.1');

it('does not regress to the inline-var background pattern on the artisan-runner page', function (): void {
    $user = notWhiteUser(true, 'not-white-dev-bg');

    $response = $this->actingAs($user)->get('/dev/artisan');

    $response->assertStatus(200);
    // The inline `style="background: var(--color-bg, ...)"` pattern
    // renders transparent in the bundled NativePHP runtime — the
    // explicit Tailwind dark-variant pattern is the safe alternative.
    // This assertion prevents a silent regression to the fragile path.
    $response->assertDontSee('style="background: var(', escape: false);
})->group('phase-16.1.1');

it('forbids /dev/artisan for a non-developer user', function (): void {
    $user = notWhiteUser(false, 'not-white-nondev');

    $response = $this->actingAs($user)->get('/dev/artisan');

    // EnsureDeveloperMode returns 404 (not 403) so the route's
    // existence stays hidden from non-developers.
    $response->assertStatus(404);
})->group('phase-16.1.1');
