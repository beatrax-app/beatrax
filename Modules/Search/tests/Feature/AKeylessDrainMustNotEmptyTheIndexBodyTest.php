<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Search\Internal\Services\SearchDocumentBody;
use Modules\Search\Public\Contracts\SearchIndexRepairContract;
use Modules\Search\Public\Contracts\SearchIndexWriterContract;

uses(RefreshDatabase::class);

// The body is the ONLY searchable copy of three sealed columns. A peer's
// catch-up is replayed inside `sync:serve`, which holds no app-lock key, and
// the codec hands an unreadable column back as the empty string rather than
// throwing — so the merge-path writer rebuilt 99 of 148 bodies out of nothing
// and the desktop stopped finding rows its own ledger still held.
/**
 * @link ../../../../.docs/features/search/architecture.md#a-column-this-process-cannot-read
 */
const BLANK_INDEX_COUNTERPARTY = 'Kappabashi Dougu';

const BLANK_INDEX_DESCRIPTION = 'kitchen knives and a whetstone';

function blankIndexKey(): string
{
    return str_repeat("\x2a", 32);
}

function blankIndexUser(): User
{
    return User::query()->create([
        'username' => 'blank-index-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function blankIndexTransaction(DatabaseManager $db, int $userId): int
{
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'Blank index test',
        'slug' => 'blank-index-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
        'created_at' => '2026-08-12 00:00:00',
        'updated_at' => '2026-08-12 00:00:00',
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/blank-index.csv',
        'sha256' => hash('sha256', 'blank-index-'.bin2hex(random_bytes(8))),
        'uploaded_at' => '2026-08-12 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-08-12 00:00:00',
        'updated_at' => '2026-08-12 00:00:00',
    ]);

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'blank-index-'.bin2hex(random_bytes(8))),
        'posted_at' => '2026-08-12',
        'booked_at' => '2026-08-12 10:00:00',
        'value_date' => '2026-08-12',
        'amount_minor' => -1280000,
        'currency' => 'JPY',
        'settled_amount_minor' => -1280000,
        'settled_currency' => 'JPY',
        'counterparty_normalized' => 'kappabashi dougu',
        'counterparty_name' => BLANK_INDEX_COUNTERPARTY,
        'normalization_version' => 3,
        'description' => BLANK_INDEX_DESCRIPTION,
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => '2026-08-12 00:00:00',
        'updated_at' => '2026-08-12 00:00:00',
    ]);
}

/**
 * @return array{0: User, 1: int, 2: int, 3: Session}
 */
function blankIndexSealedLedger(DatabaseManager $db): array
{
    /** @var Session $session */
    $session = app(Session::class);
    AppLockTestHarness::unlock($session, blankIndexKey());

    $user = blankIndexUser();
    $userId = (int) $user->id;
    $txId = blankIndexTransaction($db, $userId);

    app(EncryptionMigrationService::class)->migrate($user, $session);

    return [$user, $userId, $txId, $session];
}

it('leaves a good body standing when it cannot read the columns behind it', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    [, $userId, $txId, $session] = blankIndexSealedLedger($db);

    /** @var SearchIndexWriterContract $writer */
    $writer = app(SearchIndexWriterContract::class);
    $writer->upsertForTransaction($txId, $userId);

    $indexed = (string) $db->connection()->table('transaction_search_docs')
        ->where('transaction_id', $txId)->value('search_body');

    expect($indexed)->toContain(BLANK_INDEX_COUNTERPARTY)->toContain(BLANK_INDEX_DESCRIPTION);

    AppLockTestHarness::lock($session);
    $writer->upsertForTransaction($txId, $userId);

    expect((string) $db->connection()->table('transaction_search_docs')
        ->where('transaction_id', $txId)->value('search_body'))
        ->toBe($indexed)
        ->and($db->connection()->table('search_index_repairs')
            ->where('user_id', $userId)->where('transaction_id', $txId)->count())
        ->toBe(1);
});

it('writes no document at all rather than an empty one, and rebuilds it on the next request that holds a key', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    [$user, $userId, $txId, $session] = blankIndexSealedLedger($db);

    AppLockTestHarness::lock($session);

    /** @var SearchIndexWriterContract $writer */
    $writer = app(SearchIndexWriterContract::class);
    $writer->upsertForTransaction($txId, $userId);

    expect($db->connection()->table('transaction_search_docs')->where('transaction_id', $txId)->count())
        ->toBe(0)
        ->and($db->connection()->table('transaction_search_docs')
            ->where('search_body', SearchDocumentBody::join('', '', ''))->count())
        ->toBe(0)
        ->and($db->connection()->table('search_index_repairs')->where('user_id', $userId)->count())
        ->toBe(1);

    AppLockTestHarness::unlock(app(Session::class), blankIndexKey());
    $this->actingAs($user)->get('/notifications')->assertOk();

    expect((string) $db->connection()->table('transaction_search_docs')
        ->where('transaction_id', $txId)->value('search_body'))
        ->toContain(BLANK_INDEX_COUNTERPARTY)
        ->and($db->connection()->table('search_index_repairs')->where('user_id', $userId)->count())
        ->toBe(0);
});

it('stops re-attempting a row this keyring already failed to open, and asks again when key material moves', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    [, $userId, $txId, $session] = blankIndexSealedLedger($db);

    AppLockTestHarness::lock($session);
    app(SearchIndexWriterContract::class)->upsertForTransaction($txId, $userId);

    /** @var SearchIndexRepairContract $repair */
    $repair = app(SearchIndexRepairContract::class);

    // Still locked, so the pass rebuilds nothing. Without the bound it would
    // re-decrypt this row on every request for the life of the install, which
    // is the recurrence the op-log side already had to stop.
    expect($repair->hasWork($userId, 'keyring-a'))->toBeTrue()
        ->and($repair->repair($userId, 'keyring-a'))->toBe(0)
        ->and($repair->hasWork($userId, 'keyring-a'))->toBeFalse()
        ->and($repair->hasWork($userId, 'keyring-b'))->toBeTrue()
        ->and($db->connection()->table('search_index_repairs')->where('user_id', $userId)->count())->toBe(1);
});
