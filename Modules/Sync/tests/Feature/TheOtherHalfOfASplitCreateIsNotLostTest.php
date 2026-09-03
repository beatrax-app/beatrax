<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Internal\Merge\OpLogEntryApplier;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;

// A peer's history arrives in transport frames and nothing keeps one row's
// create ops inside a single frame. The half that lands second names a row
// this device already holds and carries required columns it will not repeat,
// so judging it an incomplete create quarantines the only carrier of the
// columns the first half never had.

function splitTailUser(string $u): User
{
    return User::query()->create(['username' => $u, 'password' => bcrypt('fixture'), 'period_start_day' => 1, 'default_currency_view' => 'eur_only']);
}

function splitTailEntry(int $pk, string $field, mixed $value, int $userId): OpLogEntry
{
    return new OpLogEntry(
        userId: $userId, deviceId: 'split-device', table: 'transactions', pk: $pk,
        field: $field, opType: OpType::CreateRow, value: json_encode($value, JSON_THROW_ON_ERROR),
        hlcL: 1, hlcC: 0, signature: str_repeat('0', 128),
    );
}

it('keeps the tail of a create that was split across two batches', function (): void {
    $db = app(DatabaseManager::class);
    $userId = (int) splitTailUser('split-create')->id;

    test()->actingAs(User::query()->findOrFail($userId));
    $session = app(Session::class);
    AppLockTestHarness::unlock($session, str_repeat('k', 32));
    app(GdkKeyringService::class)->generateAndPersist($userId, $session);

    $accountId = (int) $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId, 'name' => 'Main', 'slug' => 'main-'.$userId, 'kind' => 'checking',
        'iban' => 'NL00SPLIT'.str_pad((string) $userId, 9, '0', STR_PAD_LEFT), 'default_currency' => 'EUR',
        'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
    ]);
    $runId = (int) $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId, 'source_format' => 'demo', 'raw_file_path' => 'i.csv',
        'sha256' => str_repeat('a', 64), 'uploaded_at' => '2026-06-01 00:00:00',
        'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
    ]);
    $cpId = (int) $db->connection()->table('counterparties')->insertGetId([
        'user_id' => $userId, 'type' => 'government', 'slug' => 'belastingdienst',
        'display_name' => 'Belastingdienst', 'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
    ]);

    // Everything the registry marks required lands in the first batch, so the
    // row is admissible and inserts. The tail of the SAME create follows in the
    // next one, which is what a frame boundary does to a 34-column row.
    $head = [
        'account_id' => $accountId, 'type' => 'expense', 'posted_at' => '2026-06-10',
        'booked_at' => '2026-06-10 12:00:00', 'value_date' => '2026-06-10',
        'amount_minor' => -5511, 'currency' => 'EUR', 'settled_amount_minor' => -5511,
        'settled_currency' => 'EUR', 'counterparty_normalized' => 'belastingdienst',
        'normalization_version' => 3, 'source_format' => 'demo', 'import_run_id' => $runId,
        'source_row_index' => 1, 'fingerprint' => str_repeat('7', 64), 'fingerprint_version' => 3,
        'status' => 'cleared', 'created_at' => '2026-06-10 12:00:00',
    ];
    $tail = ['payment_type' => 'direct_debit', 'counterparty_id' => $cpId];

    $batchOne = [];
    foreach ($head as $f => $v) {
        $batchOne['transactions'][157][$f] = [splitTailEntry(157, $f, $v, $userId)];
    }

    // The tail carries created_at too: it is the same row, so the peer sends
    // the same birth time, and that is exactly what makes the collision check
    // answer "not a contradiction".
    $batchTwo = [];
    foreach ($tail + ['created_at' => '2026-06-10 12:00:00'] as $f => $v) {
        $batchTwo['transactions'][157][$f] = [splitTailEntry(157, $f, $v, $userId)];
    }

    $touched = [];
    $applier = app(OpLogEntryApplier::class);
    $applier->applyCreates($batchOne, [], $userId, '2026-06-10 12:00:00', $touched);
    $applier->applyCreates($batchTwo, [], $userId, '2026-06-10 12:00:00', $touched);

    $row = $db->connection()->table('transactions')->where('id', 157)->first();

    expect($row)->not->toBeNull()
        ->and($row->payment_type)->toBe('direct_debit', 'the tail of the create must not be discarded')
        ->and((int) $row->counterparty_id)->toBe($cpId);
});
