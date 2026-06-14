<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;

/*
 * SyncHealthPageTest — STRIDE T-11-13 information-disclosure proof.
 *
 * Verifies that the /dev/sync-health panel:
 *
 * 1. Renders only the acting user's quarantine rows (cross-user isolation,
 *    Pitfall 4: op_log_quarantine has no BelongsToUser global scope).
 * 2. Shows the correct 7-day count in the console pane header.
 * 3. Renders a calm empty state when the acting user has zero rows.
 * 4. Returns 404 for a non-developer (EnsureDeveloperMode gate, T-11-14).
 *
 * The test uses `actingAs($user)->get('/dev/sync-health')` (HTTP
 * request) rather than Livewire::test() so the route middleware stack
 * (auth + ensureDeveloperMode) is exercised end-to-end.
 */

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
 * Seed a quarantine row for the given user.
 *
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

it('renders /dev/sync-health for a developer and shows only the acting user\'s quarantine rows (Pitfall 4 isolation, T-11-13)', function (): void {
    $u1 = syncHealthUser('sync-health-u1');
    $u2 = syncHealthUser('sync-health-u2');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    // Seed one row for u1 (unique reason + device to assert isolation).
    seedQuarantineRow($db, (int) $u1->id, [
        'reason' => 'forged_signature',
        'device_id' => 'device-alpha',
    ]);

    // Seed one row for u2 (different reason + device — must NOT appear for u1).
    seedQuarantineRow($db, (int) $u2->id, [
        'reason' => 'cross_user',
        'device_id' => 'device-beta',
    ]);

    $response = $this->actingAs($u1)->get('/dev/sync-health');

    $response->assertOk();
    $html = (string) $response->getContent();

    // u1's row renders.
    expect($html)->toContain('forged_signature');
    expect($html)->toContain('device-alpha');

    // u2's row MUST NOT appear (cross-user isolation, Pitfall 4).
    expect($html)->not->toContain('cross_user');
    expect($html)->not->toContain('device-beta');
});

it('renders the 7-day count correctly in the console pane header (rose when >0)', function (): void {
    $u1 = syncHealthUser('sync-health-count');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    // Two rows within the 7-day window.
    seedQuarantineRow($db, (int) $u1->id, ['created_at' => CarbonImmutable::now()->subDays(3)->toDateTimeString()]);
    seedQuarantineRow($db, (int) $u1->id, ['created_at' => CarbonImmutable::now()->subDays(1)->toDateTimeString()]);

    // One row OUTSIDE the 7-day window — must not count.
    seedQuarantineRow($db, (int) $u1->id, ['created_at' => CarbonImmutable::now()->subDays(8)->toDateTimeString()]);

    $response = $this->actingAs($u1)->get('/dev/sync-health');

    $response->assertOk();
    $html = (string) $response->getContent();

    // The 7-day count is 2 (not 3 — the 8-day-old row is excluded).
    expect($html)->toContain('data-testid="quarantine-count"');
    // Rose color applied when count > 0 — the count cell uses #fca5a5 inline style.
    expect($html)->toContain('#fca5a5');
    // The count "2" must appear inside the count element.
    expect(preg_match('#data-testid="quarantine-count"[^>]*>\s*2\s*<#s', $html))->toBe(
        1,
        'Expected 7-day quarantine count of 2 inside data-testid="quarantine-count" element.'
    );
});

it('renders the calm empty state when the acting user has zero quarantine rows', function (): void {
    $u1 = syncHealthUser('sync-health-empty');

    // Seed a row for another user to verify empty state is per-user.
    $u2 = syncHealthUser('sync-health-empty-u2');
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    seedQuarantineRow($db, (int) $u2->id);

    $response = $this->actingAs($u1)->get('/dev/sync-health');

    $response->assertOk();
    $html = (string) $response->getContent();

    expect($html)->toContain('No skipped ops in the last 7 days.');
    expect($html)->toContain('data-testid="sync-health-empty-state"');
    // Table must NOT render.
    expect($html)->not->toContain('data-testid="sync-health-table"');

    // Count shows 0 with emerald color.
    expect($html)->toContain('#6ee7b7');
    expect(preg_match('#data-testid="quarantine-count"[^>]*>\s*0\s*<#s', $html))->toBe(
        1,
        'Expected 7-day quarantine count of 0 inside data-testid="quarantine-count" element.'
    );
});

it('returns 404 from /dev/sync-health for a non-developer (EnsureDeveloperMode gate, T-11-14)', function (): void {
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
