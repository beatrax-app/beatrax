<?php

declare(strict_types=1);

use Modules\Core\Models\User;

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
    $response->assertSee('Artisan runner');
    expect(trim((string) $response->getContent()))->not->toBe('');
})->group('phase-16.1.1');

it('does not regress to the inline-var background pattern on the artisan-runner page', function (): void {
    $user = notWhiteUser(true, 'not-white-dev-bg');

    $response = $this->actingAs($user)->get('/dev/artisan');

    $response->assertStatus(200);
    // An inline `style="background: var(...)"` renders transparent in the
    // bundled NativePHP runtime even under `<html class="dark">`, which is
    // the white-panel bug. Tailwind dark-variant utilities survive it.
    $response->assertDontSee('style="background: var(', escape: false);
})->group('phase-16.1.1');

it('forbids /dev/artisan for a non-developer user', function (): void {
    $user = notWhiteUser(false, 'not-white-nondev');

    $response = $this->actingAs($user)->get('/dev/artisan');

    // 404, not 403: a 403 would confirm the route exists.
    $response->assertStatus(404);
})->group('phase-16.1.1');
