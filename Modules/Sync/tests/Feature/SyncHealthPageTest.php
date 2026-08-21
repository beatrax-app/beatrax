<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;

// op_log_quarantine carries no BelongsToUser global scope, so nothing but the
// panel's own query keeps one user's rows off another's screen. These go through
// a real HTTP request rather than Livewire::test() so the auth and
// developer-mode middleware are exercised along with it.

function syncHealthUser(string $username, bool $isDeveloper = true): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => $isDeveloper,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function seedQuarantineRow(DatabaseManager $db, int $userId, array $overrides = []): void
{
    $db->connection()->table('op_log_quarantine')->insert(array_merge([
        'user_id' => $userId,
        'table_name' => 'transactions',
        'pk' => '1',
        'device_id' => 'device-test',
        'reason' => 'forged_signature',
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
    ], $overrides));
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-14 10:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('renders /dev/sync-health for a developer and shows only the acting user\'s quarantine rows', function (): void {
    $u1 = syncHealthUser('sync-health-u1');
    $u2 = syncHealthUser('sync-health-u2');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    seedQuarantineRow($db, (int) $u1->id, [
        'reason' => 'forged_signature',
        'device_id' => 'device-alpha',
    ]);

    seedQuarantineRow($db, (int) $u2->id, [
        'reason' => 'cross_user',
        'device_id' => 'device-beta',
    ]);

    $response = $this->actingAs($u1)->get('/dev/sync-health');

    $response->assertOk();
    $html = (string) $response->getContent();

    expect($html)->toContain('forged_signature');
    expect($html)->toContain('device-alpha');

    expect($html)->not->toContain('cross_user');
    expect($html)->not->toContain('device-beta');
});

it('renders the 7-day count correctly in the console pane header (rose when >0)', function (): void {
    $u1 = syncHealthUser('sync-health-count');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    seedQuarantineRow($db, (int) $u1->id, ['created_at' => CarbonImmutable::now()->subDays(3)->toDateTimeString()]);
    seedQuarantineRow($db, (int) $u1->id, ['created_at' => CarbonImmutable::now()->subDays(1)->toDateTimeString()]);

    // Outside the 7-day window, so it must not count.
    seedQuarantineRow($db, (int) $u1->id, ['created_at' => CarbonImmutable::now()->subDays(8)->toDateTimeString()]);

    $response = $this->actingAs($u1)->get('/dev/sync-health');

    $response->assertOk();
    $html = (string) $response->getContent();

    expect($html)->toContain('data-testid="quarantine-count"');
    // The count cell is styled rose whenever it is above zero.
    expect($html)->toContain('#fca5a5');
    expect(preg_match('#data-testid="quarantine-count"[^>]*>\s*2\s*<#s', $html))->toBe(
        1,
        'Expected 7-day quarantine count of 2 inside data-testid="quarantine-count" element.'
    );
});

it('renders the calm empty state when the acting user has zero quarantine rows', function (): void {
    $u1 = syncHealthUser('sync-health-empty');

    // Another user's row, to prove the empty state is per-user.
    $u2 = syncHealthUser('sync-health-empty-u2');
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    seedQuarantineRow($db, (int) $u2->id);

    $response = $this->actingAs($u1)->get('/dev/sync-health');

    $response->assertOk();
    $html = (string) $response->getContent();

    expect($html)->toContain('No skipped ops in the last 7 days.');
    expect($html)->toContain('data-testid="sync-health-empty-state"');
    expect($html)->not->toContain('data-testid="sync-health-table"');

    // The count cell is styled emerald at zero.
    expect($html)->toContain('#6ee7b7');
    expect(preg_match('#data-testid="quarantine-count"[^>]*>\s*0\s*<#s', $html))->toBe(
        1,
        'Expected 7-day quarantine count of 0 inside data-testid="quarantine-count" element.'
    );
});

it('table honours the same 7-day window as the header — old rows do not render and copy stays truthful', function (): void {
    $u1 = syncHealthUser('sync-health-window');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    // Only an old row, so the count is 0 and the table must show the empty state
    // rather than a stale row under a heading that says "last 7 days".
    seedQuarantineRow($db, (int) $u1->id, [
        'reason' => 'forged_signature',
        'device_id' => 'device-ancient',
        'created_at' => CarbonImmutable::now()->subDays(30)->toDateTimeString(),
    ]);

    $response = $this->actingAs($u1)->get('/dev/sync-health');

    $response->assertOk();
    $html = (string) $response->getContent();

    expect($html)->toContain('No skipped ops in the last 7 days.');
    expect($html)->toContain('data-testid="sync-health-empty-state"');
    expect($html)->not->toContain('data-testid="sync-health-table"');
    expect($html)->not->toContain('device-ancient');
    expect(preg_match('#data-testid="quarantine-count"[^>]*>\s*0\s*<#s', $html))->toBe(1);
});

it('returns 404 from /dev/sync-health for a non-developer (EnsureDeveloperMode gate)', function (): void {
    syncHealthUser('sync-health-seed');
    $nonDev = syncHealthUser('sync-health-nondev', false);

    $response = $this->actingAs($nonDev)->get('/dev/sync-health');

    $response->assertNotFound();
});

it('renders the table with reason / table_name / device_id / created_at columns when rows exist', function (): void {
    $u1 = syncHealthUser('sync-health-cols');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    seedQuarantineRow($db, (int) $u1->id, [
        'reason' => 'missing_device_key',
        'table_name' => 'categories',
        'device_id' => 'device-gamma',
        'created_at' => '2026-06-14 10:00:00',
    ]);

    $response = $this->actingAs($u1)->get('/dev/sync-health');

    $response->assertOk();
    $html = (string) $response->getContent();

    expect($html)->toContain('data-testid="sync-health-table"');
    expect($html)->toContain('missing_device_key');
    expect($html)->toContain('categories');
    expect($html)->toContain('device-gamma');
    expect($html)->toContain('2026-06-14 10:00:00');
});
