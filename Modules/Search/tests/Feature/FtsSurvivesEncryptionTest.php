<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Public\Actions\RecordTransactions;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Search\Internal\Services\SearchIndexWriter;
use Modules\Search\Public\Dto\SearchFilters;
use Modules\Search\Public\Services\SearchQuery;
use Modules\Sync\Internal\Crypto\GdkKeyringService;

/*
 * FtsSurvivesEncryptionTest — CRYPT-01 / BLOCKER-2 / D-02c: with encryption
 * enabled, a full-text query still matches merchant/description text
 * because SearchIndexWriter indexes the DECRYPTED plaintext shadow (not
 * ciphertext — FTS5 cannot index ciphertext, so the disclosed plaintext
 * shadow copy in transaction_search_docs/transaction_search_fts is an
 * accepted, honestly-disclosed exception to "encrypted at rest").
 * 14-VALIDATION.md CRYPT-01 row "Full-text search still matches...".
 */

it('a full-text search still matches a transaction description/counterparty after encryption is enabled', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    $userId = $this->searchTestUser('fts-survives-encryption-user');
    /** @var User $user */
    $user = User::query()->find($userId);

    // Prime the session with an unlocked dummy app-lock KEK — mirrors
    // Modules/Sync/tests/TestCase.php's own priming.
    /** @var Session $session */
    $session = $this->app->make(Session::class);
    (new LockStateManager)->unlock($session, str_repeat("\x2a", 32));

    /** @var GdkKeyringService $keyring */
    $keyring = $this->app->make(GdkKeyringService::class);

    $keyring->generateAndPersist($userId, $session);

    // Insert the transaction WITHOUT the TestCase's default FTS seeding —
    // the encrypted-write hook + SearchIndexWriter must build the index
    // themselves via the decrypt-before-tokenize path.
    $txId = $this->searchTestTransaction($userId, [
        'description' => 'Encrypted-at-rest groceries run',
        'counterparty_name' => 'Ciphertext Market',
    ], seedFts: false);

    /** @var SearchIndexWriter $writer */
    $writer = $this->app->make(SearchIndexWriter::class);
    $writer->upsertForTransaction($txId, $userId);

    /** @var SearchQuery $query */
    $query = $this->app->make(SearchQuery::class);
    $result = $query->search($user, 'Ciphertext Market', new SearchFilters);

    expect(collect($result->rows)->pluck('id'))->toContain($txId);

    // Disclosed-shadow assertion: the FTS body IS plaintext (D-02c honest
    // disclosure), even though the transactions.counterparty_name column
    // itself is ciphertext at rest.
    $ftsBody = $db->connection()->table('transaction_search_docs')->where('transaction_id', $txId)->value('search_body');
    expect($ftsBody)->toContain('Ciphertext Market');
});

it('the real import path (RecordTransactions, genuinely encrypted) still indexes plaintext via the TransactionImported listener', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    $userId = $this->searchTestUser('fts-real-import-encryption-user');
    /** @var User $user */
    $user = User::query()->find($userId);

    // Prime the session with an unlocked dummy app-lock KEK — mirrors
    // Modules/Sync/tests/TestCase.php's own priming.
    /** @var Session $session */
    $session = $this->app->make(Session::class);
    (new LockStateManager)->unlock($session, str_repeat("\x2a", 32));

    /** @var GdkKeyringService $keyring */
    $keyring = $this->app->make(GdkKeyringService::class);
    $keyring->generateAndPersist($userId, $session);

    $account = Account::create([
        'user_id' => $userId,
        'name' => 'ASN', 'slug' => 'fts-import-asn', 'kind' => 'asn',
        'iban' => 'NL57ASNB0123499999', 'default_currency' => 'EUR',
    ]);
    $importRun = ImportRun::create([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/fts-import.csv',
        'sha256' => str_repeat('e', 64),
        'uploaded_at' => now(),
        'status' => 'previewed',
    ]);

    $action = $this->app->make(RecordTransactions::class);
    $action([
        new CanonicalTransaction(
            userId: $userId,
            accountId: $account->id,
            type: 'expense',
            postedAt: CarbonImmutable::parse('2026-05-03'),
            bookedAt: CarbonImmutable::parse('2026-05-03 12:00:00'),
            valueDate: CarbonImmutable::parse('2026-05-03'),
            amountMinor: -1299,
            currency: 'EUR',
            settledAmountMinor: -1299,
            settledCurrency: 'EUR',
            fxRateUsed: null,
            counterpartyName: 'Real Import Merchant',
            counterpartyIban: null,
            counterpartyNormalized: 'real import merchant',
            normalizationVersion: 1,
            description: 'Genuinely encrypted groceries',
            categoryId: null,
            sourceFormat: 'asn-csv',
            importRunId: $importRun->id,
            sourceRowIndex: 0,
            sourceRef: 'FTS-IMPORT-REF-1',
        ),
    ], $user);

    // Ciphertext at rest — proves this row went through the real encrypt
    // hook, not a raw plaintext insert.
    $stored = $db->connection()->table('transactions')->where('user_id', $userId)->first();
    expect($stored->description)->not->toBe('Genuinely encrypted groceries');
    expect($stored->counterparty_name)->not->toBe('Real Import Merchant');

    // Yet full-text search still finds it — IndexTransactionOnImport ->
    // SearchIndexWriter decrypted before tokenizing (BLOCKER-2 closed).
    /** @var SearchQuery $query */
    $query = $this->app->make(SearchQuery::class);
    $result = $query->search($user, 'Real Import Merchant', new SearchFilters);

    expect(collect($result->rows)->pluck('id'))->toContain((int) $stored->id);
});
