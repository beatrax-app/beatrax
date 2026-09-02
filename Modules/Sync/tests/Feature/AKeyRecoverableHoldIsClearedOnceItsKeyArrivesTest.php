<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Public\Services\HistoryReprojector;

uses(RefreshDatabase::class);

// clearSettled() was the only thing in the codebase that deleted from
// op_log_quarantine, and it only looks at the two create-refusals. A row held
// `gdk_decrypt_failed` was therefore never cleared by anything, ever — not even
// once its epoch arrived and the pass replayed it with the key in hand.
//
// A freshly paired iPhone sat on 385 such rows across four Sync-now passes:
// "Waiting to be added" never cleared, and every later pass replayed all 385
// again — work that grows with the ledger and has no end state.
/**
 * @link ../../../../.docs/features/sync/gdk-epoch-wrap-delivery.md
 */
function keyHoldUser(): User
{
    return User::query()->create([
        'username' => 'keyhold-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

// The entry the hold is the audit row for. The pass replays what the op log
// holds for the row named, so without one it returns before reaching an answer
// — which is not the state a phone that has just synced is in.
function keyHoldEntry(DatabaseManager $db, int $userId, int $epochId): void
{
    $db->connection()->table('op_log_entries')->insert([
        'user_id' => $userId,
        'device_id' => 'keyhold-peer',
        'table_name' => 'transactions',
        'pk' => '4242',
        'field' => 'note',
        'op_type' => 'set',
        'value' => 'a value this device could not read without the key',
        'hlc_l' => 1,
        'hlc_c' => 0,
        'signature' => str_repeat('0', 128),
        'recorded_at' => '2026-09-01 23:34:23',
        'gdk_epoch' => $epochId,
        'origin_user_id' => $userId,
    ]);
}

function keyHoldQuarantine(DatabaseManager $db, int $userId, int $epochId): void
{
    $db->connection()->table('op_log_quarantine')->insert([
        'user_id' => $userId,
        'op_entry_id' => 1,
        'table_name' => 'transactions',
        'pk' => '4242',
        'device_id' => 'keyhold-peer',
        'reason' => 'gdk_decrypt_failed',
        'gdk_epoch' => $epochId,
        'hlc_l' => 1,
        'hlc_c' => 0,
        'raw_value' => 'ciphertext-the-device-could-not-open',
        'created_at' => '2026-09-01 23:34:23',
    ]);
}

/** @return list<int> */
function keyHoldIds(DatabaseManager $db, int $userId): array
{
    return array_map(
        static fn (mixed $id): int => (int) $id,
        $db->connection()->table('op_log_quarantine')->where('user_id', $userId)->pluck('id')->all(),
    );
}

it('retires a hold the pass has answered, rather than reporting it for ever', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = (int) keyHoldUser()->id;

    /** @var Session $session */
    $session = app(Session::class);
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));

    $epoch = app(GdkKeyringService::class)->generateAndPersist($userId, $session);
    keyHoldEntry($db, $userId, $epoch->epochId);
    keyHoldQuarantine($db, $userId, $epoch->epochId);

    $before = keyHoldIds($db, $userId);
    expect($before)->toHaveCount(1);

    app(HistoryReprojector::class)->replayQuarantined($userId, $session, null, null);

    expect(keyHoldIds($db, $userId))
        ->not->toContain($before[0], 'the hold outlived the pass that answered it');
});

// The other half of retiring by id: an op that fails AGAIN is re-recorded by
// the pass itself, under a new id. Retiring the old row must never swallow that
// fresh answer — the raw value it carries is the only copy there is.
it('re-records an op that still cannot be read, under a new id', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = (int) keyHoldUser()->id;

    /** @var Session $session */
    $session = app(Session::class);
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));

    $epoch = app(GdkKeyringService::class)->generateAndPersist($userId, $session);
    keyHoldEntry($db, $userId, $epoch->epochId);
    keyHoldQuarantine($db, $userId, $epoch->epochId);

    $before = keyHoldIds($db, $userId);

    app(HistoryReprojector::class)->replayQuarantined($userId, $session, null, null);

    expect(keyHoldIds($db, $userId))->toHaveCount(1)
        ->and(keyHoldIds($db, $userId))->not->toBe($before);
});

// A hold whose epoch this device still cannot obtain is NOT answered, and must
// keep driving the "waiting for a key" line rather than being swept up with it.
it('keeps a hold whose epoch this device does not have', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = (int) keyHoldUser()->id;

    /** @var Session $session */
    $session = app(Session::class);
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));

    $epoch = app(GdkKeyringService::class)->generateAndPersist($userId, $session);
    keyHoldEntry($db, $userId, $epoch->epochId);
    keyHoldQuarantine($db, $userId, 999_999_999);

    $before = keyHoldIds($db, $userId);

    app(HistoryReprojector::class)->replayQuarantined($userId, $session, null, null);

    expect(keyHoldIds($db, $userId))->toBe($before);
});
