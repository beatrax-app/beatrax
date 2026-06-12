<?php

declare(strict_types=1);

namespace Modules\Search\Public\Contracts;

/**
 * Public write contract for the FTS5 search index.
 *
 * Implemented by Modules\Search\Internal\Services\SearchIndexWriter (Plan 02).
 *
 * This contract lives in Public so that the Tax module's TagTransaction action
 * can inject it without crossing into Search Internal — maintaining the
 * module boundary enforced by BoundaryArchTest.
 *
 * Index updates are always synchronous (D-23: searchable immediately,
 * same write, no queue dependency).
 */
interface SearchIndexWriterContract
{
    /**
     * Build (or rebuild) the FTS5 search document for the given transaction.
     *
     * Reads counterparty_name, description, and any associated tax note from
     * the database, concatenates them into the search body, and upserts both
     * the `transaction_search_docs` row and the FTS5 index entry.
     *
     * Safe to call multiple times for the same transaction (idempotent upsert).
     */
    public function upsertForTransaction(int $transactionId): void;

    /**
     * Remove the FTS5 search document for the given transaction.
     *
     * Called when a transaction is permanently deleted so stale FTS entries
     * do not pollute results for future queries.
     */
    public function deleteForTransaction(int $transactionId): void;
}
