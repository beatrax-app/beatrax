<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Public\Actions\RecordTransactions;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Search\Internal\Services\SearchIndexWriter;
use Modules\Search\Public\Dto\SearchFilters;
use Modules\Search\Public\Services\SearchQuery;
use Modules\Sync\Internal\Crypto\GdkKeyringService;

// FTS5 cannot index ciphertext, so SearchIndexWriter indexes a decrypted
// plaintext shadow copy in transaction_search_docs — a knowingly accepted
// exception to "encrypted at rest".

it('a full-text search still matches a transaction description/counterparty after encryption is enabled', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    $userId = $this->searchTestUser('fts-survives-encryption-user');
    /** @var User $user */
    $user = User::query()->find($userId);

    // The keyring can only be generated while the app lock is unlocked.
    /** @var Session $session */
    $session = $this->app->make(Session::class);
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));

    /** @var GdkKeyringService $keyring */
    $keyring = $this->app->make(GdkKeyringService::class);

    $keyring->generateAndPersist($userId, $session);

    // Skipping the fixture's FTS seeding forces the encrypted-write hook and
    // SearchIndexWriter to build the index over the decrypt-before-tokenize path.
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

    // The shadow body is plaintext by design, even though the
    // transactions.counterparty_name column itself is ciphertext at rest.
    $ftsBody = $db->connection()->table('transaction_search_docs')->where('transaction_id', $txId)->value('search_body');
    expect($ftsBody)->toContain('Ciphertext Market');
});

it('the real import path (RecordTransactions, genuinely encrypted) still indexes plaintext via the TransactionImported listener', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    $userId = $this->searchTestUser('fts-real-import-encryption-user');
    /** @var User $user */
    $user = User::query()->find($userId);

    /** @var Session $session */
    $session = $this->app->make(Session::class);
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));

    /** @var GdkKeyringService $keyring */
    $keyring = $this->app->make(GdkKeyringService::class);
    $keyring->generateAndPersist($userId, $session);

    $account = Account::create([
        'user_id' => $userId,
        'name' => 'ASN', 'slug' => 'fts-import-asn', 'kind' => 'bank',
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

    // Ciphertext at rest proves the row went through the real encrypt hook
    // rather than a raw plaintext insert.
    $stored = $db->connection()->table('transactions')->where('user_id', $userId)->first();
    expect($stored->description)->not->toBe('Genuinely encrypted groceries');
    expect($stored->counterparty_name)->not->toBe('Real Import Merchant');

    // Search still finds it because IndexTransactionOnImport decrypts before
    // handing the text to SearchIndexWriter.
    /** @var SearchQuery $query */
    $query = $this->app->make(SearchQuery::class);
    $result = $query->search($user, 'Real Import Merchant', new SearchFilters);

    expect(collect($result->rows)->pluck('id'))->toContain((int) $stored->id);
});
