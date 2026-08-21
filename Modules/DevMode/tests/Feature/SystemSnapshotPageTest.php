<?php

declare(strict_types=1);

use Modules\Core\Models\User;

function systemSnapshotUser(string $username, bool $isDeveloper = true): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => $isDeveloper,
    ]);
}

it('renders /dev/system for a developer with section headings for PHP / Laravel / SQLite / Env / Config', function (): void {
    $user = systemSnapshotUser('sysnap-dev');

    $response = $this->actingAs($user)->get('/dev/system');

    $response->assertOk();
    $response->assertSee('System snapshot', escape: false);
    $response->assertSee('PHP', escape: false);
    $response->assertSee('Laravel', escape: false);
    $response->assertSee('SQLite', escape: false);
    $response->assertSee('Environment', escape: false);
    $response->assertSee('Paths', escape: false);
});

it('returns 404 from /dev/system for a non-developer', function (): void {
    systemSnapshotUser('sysnap-seed');
    $user = systemSnapshotUser('sysnap-nondev', false);

    $response = $this->actingAs($user)->get('/dev/system');

    $response->assertNotFound();
});

it('does not render the test-env APP_KEY value on /dev/system (denylist redaction)', function (): void {
    $user = systemSnapshotUser('sysnap-no-leak');

    $appKey = config('app.key');
    expect(is_string($appKey) && $appKey !== '')->toBeTrue('APP_KEY must be set for the redaction test');

    $response = $this->actingAs($user)->get('/dev/system');
    $response->assertOk();
    $html = (string) $response->getContent();

    expect($html)->not->toContain((string) $appKey);
    expect($html)->toContain('[REDACTED]');
});
