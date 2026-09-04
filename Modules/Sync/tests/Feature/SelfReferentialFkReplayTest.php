<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Internal\Merge\OpLogEntryApplier;
use Modules\Sync\Internal\Merge\SearchDocumentRows;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;

// transactions.pair_transaction_id is a foreign key onto transactions itself and
// a transfer pair points both ways, so no insert order satisfies it: whichever
// row lands first names a partner that does not exist yet. On a phone pulling a
// desktop's history that threw FOREIGN KEY constraint failed and rolled the replay back.

function selfRefUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function selfRefAccount(DatabaseManager $db, int $userId): int
{
    return (int) $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'Main',
        'slug' => 'main-'.$userId,
        'kind' => 'checking',
        'iban' => 'NL00SELF'.str_pad((string) $userId, 10, '0', STR_PAD_LEFT),
        'default_currency' => 'EUR',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);
}

function selfRefUnlock(int $userId): void
{
    test()->actingAs(User::query()->findOrFail($userId));

    $session = app(Session::class);
    AppLockTestHarness::unlock($session, str_repeat('k', 32));
    app(GdkKeyringService::class)->generateAndPersist($userId, $session);
}

function selfRefEntry(int $pk, string $field, string $value, int $userId, int $hlc): OpLogEntry
{
    return new OpLogEntry(
        userId: $userId,
        deviceId: 'self-ref-device',
        table: 'transactions',
        pk: $pk,
        field: $field,
        opType: OpType::CreateRow,
        value: $value,
        hlcL: $hlc,
        hlcC: 0,
        signature: str_repeat('0', 128),
    );
}

/**
 * @return array<string, array<int|string, array<string, list<OpLogEntry>>>>
 */
function selfRefPairCreates(int $userId, int $accountId, int $importRunId): array
{
    $creates = [];

    // 251 names 295 as its partner and 295 names 251 — the real shape from
    // the failing log, carrying every column the registry marks required.
    foreach ([[251, 295, 'transfer_out', -10000], [295, 251, 'transfer_in', 10000]] as [$pk, $partner, $type, $amount]) {
        foreach ([
            'account_id' => $accountId,
            'type' => $type,
            'posted_at' => '2026-06-10',
            'booked_at' => '2026-06-10 12:00:00',
            'value_date' => '2026-06-10',
            'amount_minor' => $amount,
            'currency' => 'EUR',
            'settled_amount_minor' => $amount,
            'settled_currency' => 'EUR',
            'counterparty_normalized' => 'partner',
            'normalization_version' => 3,
            'source_format' => 'demo',
            'import_run_id' => $importRunId,
            'source_row_index' => $pk,
            'fingerprint' => str_repeat((string) ($pk % 10), 64),
            'fingerprint_version' => 3,
            'status' => 'cleared',
            'pair_transaction_id' => $partner,
        ] as $field => $value) {
            // op-log values are JSON scalars on the wire, not raw strings.
            $encoded = json_encode($value, JSON_THROW_ON_ERROR);
            $creates['transactions'][$pk][$field] = [selfRefEntry($pk, $field, $encoded, $userId, 1)];
        }
    }

    return $creates;
}

function selfRefImportRun(DatabaseManager $db, int $userId): int
{
    return (int) $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'demo',
        'raw_file_path' => 'imports/self-ref.csv',
        'sha256' => str_repeat('a', 64),
        'uploaded_at' => '2026-06-01 00:00:00',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);
}

it('applies a mutually-referencing transfer pair without a foreign-key failure', function (): void {
    $db = app(DatabaseManager::class);

    $userId = (int) selfRefUser('self-ref-replay')->id;

    selfRefUnlock($userId);
    $accountId = selfRefAccount($db, $userId);

    $touched = new SearchDocumentRows($db);

    /** @var OpLogEntryApplier $applier */
    $applier = app(OpLogEntryApplier::class);

    $applier->applyCreates(
        selfRefPairCreates($userId, $accountId, selfRefImportRun($db, $userId)),
        [],
        $userId,
        '2026-06-10 12:00:00',
        $touched,
    );

    $rows = $db->connection()->table('transactions')
        ->whereIn('id', [251, 295])
        ->orderBy('id')
        ->get(['id', 'pair_transaction_id']);

    expect($rows)->toHaveCount(2, 'both halves of the pair must exist');

    // The deferred pass runs once both rows are present, so each side ends up
    // pointing at the other rather than being left null.
    expect((int) $rows[0]->pair_transaction_id)->toBe(295)
        ->and((int) $rows[1]->pair_transaction_id)->toBe(251);
});

it('leaves a self-reference null when its target never arrives', function (): void {
    $db = app(DatabaseManager::class);

    $userId = (int) selfRefUser('dangling-ref-replay')->id;

    selfRefUnlock($userId);
    $accountId = selfRefAccount($db, $userId);

    $creates = selfRefPairCreates($userId, $accountId, selfRefImportRun($db, $userId));
    // Drop the partner: 251 now points at a row that is not in this batch and
    // never will be, which must cost 251 its link and nothing more.
    unset($creates['transactions'][295]);

    $touched = new SearchDocumentRows($db);

    /** @var OpLogEntryApplier $applier */
    $applier = app(OpLogEntryApplier::class);

    $applier->applyCreates($creates, [], $userId, '2026-06-10 12:00:00', $touched);

    $row = $db->connection()->table('transactions')->where('id', 251)->first(['pair_transaction_id']);

    expect($row)->not->toBeNull('the row itself must still be applied')
        ->and($row->pair_transaction_id)->toBeNull();
});
