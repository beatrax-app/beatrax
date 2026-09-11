<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Internal\Merge\ArrivingBatch;
use Modules\Sync\Internal\Merge\OpLogEntryApplier;
use Modules\Sync\Internal\Merge\ReplayedRows;
use Modules\Sync\Internal\Merge\SelfReferenceDeferral;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;

uses(RefreshDatabase::class);

// PeerRowAliases::translate() walks CoveredTableOrder::parentColumns(), and that
// excludes a column whose foreign key targets its own table — rightly, because
// no insertion order satisfies a transfer pair. transactions.pair_transaction_id
// and categories.parent_id are therefore the two ids no peer op ever translates.

const ALIASED_LINK_DEVICE = 'the-desktop-that-imported-first';

function aliasedLinkUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function aliasedLinkUnlock(int $userId): void
{
    test()->actingAs(User::query()->findOrFail($userId));

    $session = app(Session::class);
    AppLockTestHarness::unlock($session, str_repeat('k', 32));
    app(GdkKeyringService::class)->generateAndPersist($userId, $session);
}

function aliasedLinkDb(): DatabaseManager
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return $db;
}

function aliasedLinkAccount(int $userId): int
{
    return (int) aliasedLinkDb()->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'Main',
        'slug' => 'aliased-link-'.$userId,
        'kind' => 'checking',
        'iban' => 'NL00LINK'.str_pad((string) $userId, 10, '0', STR_PAD_LEFT),
        'default_currency' => 'EUR',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);
}

function aliasedLinkImportRun(int $userId): int
{
    return (int) aliasedLinkDb()->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'demo',
        'raw_file_path' => 'imports/aliased-link.csv',
        'sha256' => str_repeat('a', 64),
        'uploaded_at' => '2026-06-01 00:00:00',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);
}

// Both unique indexes transactions declares read the date, the amount and the
// fingerprint, so each fixture row varies all three: a row matching another on
// them is a twin, and the create path re-homes a twin instead of inserting it.
/**
 * @return array<string, mixed>
 */
function aliasedLinkTransaction(int $accountId, int $importRunId, string $type, string $day, int $amountMinor, string $fingerprintSeed): array
{
    return [
        'account_id' => $accountId,
        'import_run_id' => $importRunId,
        'type' => $type,
        'posted_at' => $day,
        'booked_at' => $day.' 12:00:00',
        'value_date' => $day,
        'amount_minor' => $amountMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $amountMinor,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'own account',
        'normalization_version' => 3,
        'source_format' => 'demo',
        'source_row_index' => 0,
        'occurrence_ordinal' => 0,
        'fingerprint' => str_pad($fingerprintSeed, 64, '0'),
        'fingerprint_version' => 3,
        'status' => 'cleared',
    ];
}

// A row this device already holds, written the way an import writes it rather
// than through the merge: the id is this device's own autoincrement, which is
// the whole reason it disagrees with the peer's.
/**
 * @param  array<string, mixed>  $columns
 */
function aliasedLinkStore(string $table, int $userId, array $columns): int
{
    return (int) aliasedLinkDb()->connection()->table($table)->insertGetId([
        'user_id' => $userId,
        ...$columns,
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);
}

function aliasedLinkAlias(int $userId, string $table, int $remoteId, int $localId): void
{
    aliasedLinkDb()->connection()->table('op_log_row_aliases')->insert([
        'user_id' => $userId,
        'table_name' => $table,
        'device_id' => ALIASED_LINK_DEVICE,
        'remote_id' => (string) $remoteId,
        'local_id' => (string) $localId,
        'created_at' => '2026-06-01 00:00:00',
    ]);
}

function aliasedLinkEntry(string $table, int $pk, string $field, ?string $value, OpType $opType, int $userId): OpLogEntry
{
    return new OpLogEntry(
        userId: $userId,
        deviceId: ALIASED_LINK_DEVICE,
        table: $table,
        pk: $pk,
        field: $field,
        opType: $opType,
        value: $value,
        hlcL: 1,
        hlcC: 0,
        signature: str_repeat('0', 128),
    );
}

/**
 * @param  array<string, mixed>  $columns
 * @return array<string, array<int|string, array<string, list<OpLogEntry>>>>
 */
function aliasedLinkCreate(string $table, int $pk, array $columns, int $userId): array
{
    $creates = [];

    foreach ($columns as $field => $value) {
        // op-log values are JSON scalars on the wire, not raw strings.
        $encoded = json_encode($value, JSON_THROW_ON_ERROR);
        $creates[$table][$pk][$field] = [aliasedLinkEntry($table, $pk, $field, $encoded, OpType::CreateRow, $userId)];
    }

    return $creates;
}

/**
 * @param  array<string, array<int|string, array<string, list<OpLogEntry>>>>  $creates
 */
function aliasedLinkApplyCreates(array $creates, int $userId): void
{
    /** @var OpLogEntryApplier $applier */
    $applier = app(OpLogEntryApplier::class);

    $applier->applyCreates($creates, [], new ArrivingBatch($userId, '2026-06-10 12:00:00'), new ReplayedRows(aliasedLinkDb()));
}

function aliasedLinkApplySet(string $table, int $pk, string $field, int $value, int $userId): void
{
    /** @var OpLogEntryApplier $applier */
    $applier = app(OpLogEntryApplier::class);

    $pendingDeletes = [];

    $applier->applyFieldMerges(
        [$table => [$pk => [$field => [aliasedLinkEntry($table, $pk, $field, json_encode($value, JSON_THROW_ON_ERROR), OpType::Set, $userId)]]]],
        [],
        new ArrivingBatch($userId, '2026-06-10 12:00:00'),
        $pendingDeletes,
        new ReplayedRows(aliasedLinkDb()),
    );
}

function aliasedLinkPairOf(int $txId): ?int
{
    $value = aliasedLinkDb()->connection()->table('transactions')->where('id', $txId)->value('pair_transaction_id');

    return is_numeric($value) ? (int) $value : null;
}

function aliasedLinkParentOf(int $categoryId): ?int
{
    $value = aliasedLinkDb()->connection()->table('categories')->where('id', $categoryId)->value('parent_id');

    return is_numeric($value) ? (int) $value : null;
}

function aliasedLinkHistory(string $table, int $pk, string $column, int $target, int $userId): void
{
    aliasedLinkDb()->connection()->table('op_log_entries')->insert([
        'user_id' => $userId,
        'device_id' => ALIASED_LINK_DEVICE,
        'table_name' => $table,
        'pk' => (string) $pk,
        'field' => $column,
        'op_type' => 'create_row',
        'value' => json_encode($target, JSON_THROW_ON_ERROR),
        'hlc_l' => 1,
        'hlc_c' => 0,
        'signature' => str_repeat('0', 128),
        'recorded_at' => '2026-06-10 12:00:00',
    ]);
}

// The peer's transfer_in is here under an id of this device's own, and the
// peer's id for it names an unrelated purchase. resolvableTargets() asks only
// whether a row EXISTS at the id, so that purchase answers for the partner.
it('writes a deferred pair link against the row this device holds, not the row at the peer id', function (): void {
    $userId = (int) aliasedLinkUser('aliased-link-pair')->id;

    aliasedLinkUnlock($userId);
    $accountId = aliasedLinkAccount($userId);
    $runId = aliasedLinkImportRun($userId);

    $squatter = aliasedLinkStore('transactions', $userId, aliasedLinkTransaction($accountId, $runId, 'expense', '2026-03-03', -500, 'd'));
    $partner = aliasedLinkStore('transactions', $userId, aliasedLinkTransaction($accountId, $runId, 'transfer_in', '2026-06-10', 10000, 'b'));

    aliasedLinkAlias($userId, 'transactions', $squatter, $partner);

    aliasedLinkApplyCreates(aliasedLinkCreate('transactions', 90251, [
        ...aliasedLinkTransaction($accountId, $runId, 'transfer_out', '2026-06-10', -10000, 'a'),
        'pair_transaction_id' => $squatter,
    ], $userId), $userId);

    expect(aliasedLinkPairOf(90251))->toBe($partner, 'the leg must name the partner this device holds')
        ->and(aliasedLinkPairOf($squatter))->toBeNull('the purchase sitting at the peer id is not part of any pair');
});

// The same map, reached from the other feed: TransferPairer pairs a new leg
// against an older one, so the link routinely arrives as a Set on a row that
// already exists rather than inside the create.
it('writes a deferred pair link from a Set against the row this device holds', function (): void {
    $userId = (int) aliasedLinkUser('aliased-link-pair-set')->id;

    aliasedLinkUnlock($userId);
    $accountId = aliasedLinkAccount($userId);
    $runId = aliasedLinkImportRun($userId);

    $squatter = aliasedLinkStore('transactions', $userId, aliasedLinkTransaction($accountId, $runId, 'expense', '2026-03-03', -500, 'd'));
    $partner = aliasedLinkStore('transactions', $userId, aliasedLinkTransaction($accountId, $runId, 'transfer_in', '2026-06-10', 10000, 'b'));
    $leg = aliasedLinkStore('transactions', $userId, aliasedLinkTransaction($accountId, $runId, 'transfer_out', '2026-06-10', -10000, 'a'));

    aliasedLinkAlias($userId, 'transactions', $squatter, $partner);

    aliasedLinkApplySet('transactions', $leg, 'pair_transaction_id', $squatter, $userId);

    expect(aliasedLinkPairOf($leg))->toBe($partner)
        ->and(aliasedLinkPairOf($squatter))->toBeNull();
});

// Both devices seed their own default tree, so a category id is the id a peer
// happened to take for it. Re-parenting a category under whichever row sits at
// that id moves it, and every breadcrumb and rollup reading the tree follows.
it('writes a deferred parent link against the category this device holds', function (): void {
    $userId = (int) aliasedLinkUser('aliased-link-parent')->id;

    aliasedLinkUnlock($userId);

    $squatter = aliasedLinkStore('categories', $userId, ['name' => 'Holidays', 'slug' => 'aliased-holidays', 'kind' => 'expense']);
    $parent = aliasedLinkStore('categories', $userId, ['name' => 'Housing', 'slug' => 'aliased-housing', 'kind' => 'expense']);

    aliasedLinkAlias($userId, 'categories', $squatter, $parent);

    aliasedLinkApplyCreates(aliasedLinkCreate('categories', 90109, [
        'name' => 'Rent',
        'slug' => 'aliased-rent',
        'kind' => 'expense',
        'parent_id' => $squatter,
    ], $userId), $userId);

    expect(aliasedLinkParentOf(90109))->toBe($parent, 'the child must hang under the category this device holds')
        ->and(aliasedLinkParentOf($parent))->toBeNull();
});

// resolveFromHistory() reads op_log_entries raw, so it is the one path with no
// applier above it to translate anything. The device_id beside the entry is
// what makes the map answerable there.
it('resolves a link swept out of the log through the alias map', function (): void {
    $userId = (int) aliasedLinkUser('aliased-link-sweep')->id;

    aliasedLinkUnlock($userId);
    $accountId = aliasedLinkAccount($userId);
    $runId = aliasedLinkImportRun($userId);

    $squatter = aliasedLinkStore('transactions', $userId, aliasedLinkTransaction($accountId, $runId, 'expense', '2026-03-03', -500, 'd'));
    $partner = aliasedLinkStore('transactions', $userId, aliasedLinkTransaction($accountId, $runId, 'transfer_in', '2026-06-10', 10000, 'b'));
    $leg = aliasedLinkStore('transactions', $userId, aliasedLinkTransaction($accountId, $runId, 'transfer_out', '2026-06-10', -10000, 'a'));

    aliasedLinkAlias($userId, 'transactions', $squatter, $partner);
    aliasedLinkHistory('transactions', $leg, 'pair_transaction_id', $squatter, $userId);

    expect(app(SelfReferenceDeferral::class)->resolveFromHistory($userId))->toBe(1)
        ->and(aliasedLinkPairOf($leg))->toBe($partner)
        ->and(aliasedLinkPairOf($squatter))->toBeNull();
});

// The entry's pk is the peer's too, and the sweep both reads the row and writes
// it by that id. A re-homed leg left the link on the local row squatting at the
// peer's id — a row the peer has never seen.
it('sweeps the row the peer pk names here, not the row at the peer pk', function (): void {
    $userId = (int) aliasedLinkUser('aliased-link-sweep-pk')->id;

    aliasedLinkUnlock($userId);
    $accountId = aliasedLinkAccount($userId);
    $runId = aliasedLinkImportRun($userId);

    $squatter = aliasedLinkStore('transactions', $userId, aliasedLinkTransaction($accountId, $runId, 'expense', '2026-03-03', -500, 'd'));
    $partner = aliasedLinkStore('transactions', $userId, aliasedLinkTransaction($accountId, $runId, 'transfer_in', '2026-06-10', 10000, 'b'));
    $leg = aliasedLinkStore('transactions', $userId, aliasedLinkTransaction($accountId, $runId, 'transfer_out', '2026-06-10', -10000, 'a'));

    aliasedLinkAlias($userId, 'transactions', $squatter, $leg);
    aliasedLinkHistory('transactions', $squatter, 'pair_transaction_id', $partner, $userId);

    expect(app(SelfReferenceDeferral::class)->resolveFromHistory($userId))->toBe(1)
        ->and(aliasedLinkPairOf($leg))->toBe($partner, 'the sweep must repair the re-homed leg')
        ->and(aliasedLinkPairOf($squatter))->toBeNull('the purchase at the peer id is not the row the entry describes');
});

// The control that a rewrite-always fix fails. Most peers agree with this
// device about most ids, and an id with no alias is the peer's own and correct.
it('leaves a deferred link alone when no alias claims the id', function (): void {
    $userId = (int) aliasedLinkUser('aliased-link-no-alias')->id;

    aliasedLinkUnlock($userId);
    $accountId = aliasedLinkAccount($userId);
    $runId = aliasedLinkImportRun($userId);

    $partner = aliasedLinkStore('transactions', $userId, aliasedLinkTransaction($accountId, $runId, 'transfer_in', '2026-06-10', 10000, 'b'));

    aliasedLinkApplyCreates(aliasedLinkCreate('transactions', 90251, [
        ...aliasedLinkTransaction($accountId, $runId, 'transfer_out', '2026-06-10', -10000, 'a'),
        'pair_transaction_id' => $partner,
    ], $userId), $userId);

    expect(aliasedLinkPairOf(90251))->toBe($partner, 'an id no alias claims is the id the peer meant');
});
