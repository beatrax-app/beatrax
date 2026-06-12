<?php

declare(strict_types=1);

namespace Modules\Search\Internal\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Search\Public\Contracts\SearchIndexWriterContract;

/**
 * Synchronous writer for the FTS5 search index.
 *
 * Keeps `transaction_search_docs` (denormalized content table) and
 * `transaction_search_fts` (FTS5 virtual table) in lockstep on every
 * transaction write.
 *
 * Design constraints:
 *   - D-23: searchable immediately, same write — no queue, no async.
 *   - Pitfall-2: NO try/catch swallow — failures must bubble so the outer
 *     import chunk transaction rolls back cleanly. search:reindex is the
 *     recovery tool for any desync.
 *   - search_body separator is chr(12) (form-feed) — NOT trigram-indexable,
 *     avoids false-positive cross-field matches (RESEARCH Assumption A2).
 *   - Idempotent: calling upsertForTransaction multiple times for the same
 *     id produces the same result (FTS delete-then-insert is safe to repeat).
 */
final class SearchIndexWriter implements SearchIndexWriterContract
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    /**
     * Build (or rebuild) the FTS5 search document for the given transaction.
     *
     * Reads counterparty_name, description, and any associated tax note from
     * the database, concatenates them into the search body (separated by
     * chr(12)), and upserts both the `transaction_search_docs` row and the
     * FTS5 index entry.
     */
    public function upsertForTransaction(int $transactionId): void
    {
        $connection = $this->db->connection();

        // Fetch the transaction's text fields. If the transaction does not
        // exist, return silently (no-op, no exception).
        $tx = $connection
            ->table('transactions')
            ->select(['id', 'user_id', 'counterparty_name', 'description'])
            ->where('id', $transactionId)
            ->first();

        if ($tx === null) {
            return;
        }

        $userId = is_numeric($tx->user_id) ? (int) $tx->user_id : 0;
        $counterparty = is_string($tx->counterparty_name) ? $tx->counterparty_name : '';
        $description = is_string($tx->description) ? $tx->description : '';

        // Fetch the tax note for this transaction (if any).
        $tag = $connection
            ->table('tax_transaction_tags')
            ->select(['note'])
            ->where('transaction_id', $transactionId)
            ->where('user_id', $userId)
            ->first();

        $note = ($tag !== null && is_string($tag->note)) ? $tag->note : '';

        // Build the denormalized search body.
        // chr(12) = form-feed — not trigram-indexable, avoids cross-field
        // false positives (RESEARCH Assumption A2).
        $newBody = $counterparty . chr(12) . $description . chr(12) . $note;

        // Read the OLD body before upserting so we can delete the stale FTS entry.
        $existingDoc = $connection
            ->table('transaction_search_docs')
            ->select(['search_body'])
            ->where('transaction_id', $transactionId)
            ->first();

        $oldBody = ($existingDoc !== null && is_string($existingDoc->search_body))
            ? $existingDoc->search_body
            : '';

        // Upsert the denormalized doc (unique on transaction_id).
        $connection->table('transaction_search_docs')->upsert(
            [
                'transaction_id' => $transactionId,
                'user_id'        => $userId,
                'search_body'    => $newBody,
            ],
            ['transaction_id'],
            ['user_id', 'search_body'],
        );

        // Sync the FTS5 index.
        // (a) Delete the old FTS entry — only when a prior doc existed.
        if ($oldBody !== '') {
            $connection->statement(
                "INSERT INTO transaction_search_fts(transaction_search_fts, rowid, search_body) VALUES('delete', ?, ?)",
                [$transactionId, $oldBody],
            );
        }

        // (b) Insert the new FTS entry.
        $connection->statement(
            'INSERT INTO transaction_search_fts(rowid, search_body) VALUES(?, ?)',
            [$transactionId, $newBody],
        );
    }

    /**
     * Remove the FTS5 search document for the given transaction.
     *
     * Called when a transaction is permanently deleted so stale FTS entries
     * do not pollute results for future queries.
     */
    public function deleteForTransaction(int $transactionId): void
    {
        $connection = $this->db->connection();

        // Read the existing body for the FTS delete step.
        $existingDoc = $connection
            ->table('transaction_search_docs')
            ->select(['search_body'])
            ->where('transaction_id', $transactionId)
            ->first();

        $oldBody = ($existingDoc !== null && is_string($existingDoc->search_body))
            ? $existingDoc->search_body
            : '';

        if ($oldBody !== '') {
            // Delete from the FTS virtual table first.
            $connection->statement(
                "INSERT INTO transaction_search_fts(transaction_search_fts, rowid, search_body) VALUES('delete', ?, ?)",
                [$transactionId, $oldBody],
            );
        }

        // Remove the content doc.
        $connection->table('transaction_search_docs')
            ->where('transaction_id', $transactionId)
            ->delete();
    }
}
