<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
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
 *
 * RED until Plan 04 wires SearchIndexWriter to decrypt via the Sync Public
 * SensitiveColumnCodec before tokenizing, and Plan 02 ships
 * Modules\Sync\Internal\Crypto\GdkKeyringService (the encryption-enabled
 * precondition this test exercises). This test references the planned
 * production FQCN, which does not yet exist — the failure is "class not
 * found", the correct Wave 0 RED state.
 */

it('a full-text search still matches a transaction description/counterparty after encryption is enabled', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    $userId = $this->searchTestUser('fts-survives-encryption-user');
    /** @var \Modules\Core\Models\User $user */
    $user = \Modules\Core\Models\User::query()->find($userId);

    /** @var GdkKeyringService $keyring */
    $keyring = $this->app->make(GdkKeyringService::class);

    /** @var Session $session */
    $session = $this->app->make(Session::class);

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
