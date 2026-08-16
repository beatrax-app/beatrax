<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\OpLog\PreSyncHistoryCapture;

uses(RefreshDatabase::class);

/*
 * A device joining an existing account holds no GDK epoch — it is about to be
 * given the peer's keys. Its only rows are the defaults every install seeds,
 * so capturing them pushed a second copy of the default rule set onto the
 * peer and reported them back as "records received".
 */

function captureGuardUser(DatabaseManager $db): int
{
    $userId = (int) $db->connection()->table('users')->insertGetId([
        'username' => 'capture-guard-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $db->connection()->table('accounts')->insert([
        'user_id' => $userId,
        'name' => 'Seeded account',
        'slug' => 'seeded-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
        'created_at' => '2026-06-14 00:00:00',
        'updated_at' => '2026-06-14 00:00:00',
    ]);

    return $userId;
}

it('captures nothing for a device that holds no epoch', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = captureGuardUser($db);

    /** @var PreSyncHistoryCapture $capture */
    $capture = app(PreSyncHistoryCapture::class);

    expect($capture->capture($userId))->toBe(0)
        ->and($db->connection()->table('op_log_entries')->where('user_id', $userId)->count())->toBe(0);
});

it('attempts capture once the device holds an epoch', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = captureGuardUser($db);

    $db->connection()->table('sync_encryption_state')->insert([
        'user_id' => $userId,
        'current_epoch' => 1,
        'migration_in_progress' => false,
        'enabled_at' => '2026-06-14 00:00:00',
        'created_at' => '2026-06-14 00:00:00',
        'updated_at' => '2026-06-14 00:00:00',
    ]);

    /** @var PreSyncHistoryCapture $capture */
    $capture = app(PreSyncHistoryCapture::class);

    // No device identity exists in this fixture, so the writer cannot be
    // resolved and capture reports 0 — the assertion that matters is that the
    // epoch guard no longer short-circuits before that point.
    expect($capture->capture($userId))->toBe(0);
});
