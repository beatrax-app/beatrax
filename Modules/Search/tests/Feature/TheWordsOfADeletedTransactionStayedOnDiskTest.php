<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Search\Public\Dto\SearchFilters;
use Modules\Search\Public\Services\SearchQuery;

// The index body is a plaintext shadow of sealed columns, so a document that
// outlives its transaction keeps a deleted purchase's merchant name readable
// on disk. A database cascade deleted rows without telling the writer until it
// was removed tree-wide; the cause went and the residue did not. Measured on a
// real install: 102 documents with no transaction, and a search for one of
// their merchants answered 13 rows that do not exist.

const ORPHAN_DOC_MIGRATION = 'Modules/Search/Database/Migrations/2026_09_10_000002_forget_the_words_of_transactions_that_are_gone.php';

function orphanDocRunMigration(): void
{
    $migration = require base_path(ORPHAN_DOC_MIGRATION);

    $migration->up();
}

function orphanDocCount(): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return (int) $db->connection()->table('transaction_search_docs as d')
        ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('transactions as t')->whereColumn('t.id', 'd.transaction_id'))
        ->count();
}

// Straight at the FTS table rather than through SearchQuery, because the query
// joins transactions and would hide a term the index still holds. What is being
// removed is the term, not the row's reachability.
function orphanDocFtsMatches(string $needle): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    /** @var list<object> $rows */
    $rows = $db->connection()->select(
        'SELECT rowid FROM transaction_search_fts WHERE transaction_search_fts MATCH ?',
        [$needle],
    );

    return count($rows);
}

// What the cascade did: the row goes, and nothing tells the writer.
function orphanDocDeleteRawly(int $transactionId): void
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $db->connection()->table('transactions')->where('id', $transactionId)->delete();
}

it('forgets the words of a transaction that is gone, in the index and in the FTS terms', function (): void {
    $userId = $this->searchTestUser('orphan-doc-'.bin2hex(random_bytes(4)));

    $deleted = $this->searchTestTransaction($userId, ['counterparty_name' => 'Kaaswinkel Zaandam']);
    $kept = $this->searchTestTransaction($userId, ['counterparty_name' => 'Bloemist Haarlem']);

    /** @var User $user */
    $user = User::query()->findOrFail($userId);

    expect(app(SearchQuery::class)->search($user, 'Kaaswinkel', SearchFilters::empty())->totalCount)->toBe(1);

    orphanDocDeleteRawly($deleted);

    // The denominator: the words are still there when nothing has cleaned up,
    // or an absence after the migration proves only that they were never found.
    expect(orphanDocCount())->toBe(1)
        ->and(orphanDocFtsMatches('Kaaswinkel'))->toBe(1);

    orphanDocRunMigration();

    expect(orphanDocCount())->toBe(0, 'the document outlived the transaction it describes');

    // The half a plain DELETE would miss: transaction_search_fts is external
    // content, so removing the row leaves the terms behind.
    expect(orphanDocFtsMatches('Kaaswinkel'))->toBe(0, 'the FTS index still holds the words of a deleted purchase');

    // The control: a live transaction keeps its document and its terms.
    expect(orphanDocFtsMatches('Bloemist'))->toBe(1)
        ->and(app(SearchQuery::class)->search($user, 'Bloemist', SearchFilters::empty())->totalCount)->toBe(1);

    expect($kept)->toBeGreaterThan(0);
});

it('leaves an install with nothing to forget exactly as it found it', function (): void {
    $userId = $this->searchTestUser('orphan-none-'.bin2hex(random_bytes(4)));
    $this->searchTestTransaction($userId, ['counterparty_name' => 'Bakker Utrecht']);

    orphanDocRunMigration();

    expect(orphanDocCount())->toBe(0)
        ->and(orphanDocFtsMatches('Bakker'))->toBe(1);
});
