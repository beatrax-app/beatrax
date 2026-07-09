<?php

declare(strict_types=1);

namespace Modules\Search\Internal\Services;

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Search\Public\Contracts\SearchIndexWriterContract;
use Modules\Sync\Public\Services\SensitiveColumnCodec;

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
 *     id produces the same result. The docs upsert + FTS delete + FTS insert
 *     run inside a single DB transaction so a partial write can never desync
 *     the inverted index (CR-03).
 *   - CR-02: every write verifies the caller-supplied $actorUserId against the
 *     stored owner so a forged transaction id can never touch another user's
 *     index doc.
 */
final class SearchIndexWriter implements SearchIndexWriterContract
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly SensitiveColumnCodec $codec,
        private readonly Session $session,
    ) {}

    /**
     * Build (or rebuild) the FTS5 search document for the given transaction.
     *
     * Reads counterparty_name, description, and any associated tax note from
     * the database, concatenates them into the search body (separated by
     * chr(12)), and upserts both the `transaction_search_docs` row and the
     * FTS5 index entry.
     */
    public function upsertForTransaction(int $transactionId, int $actorUserId): void
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

        // CR-02: refuse to (re)index a transaction the actor does not own.
        // The contract takes an explicit actor so a caller can never rebuild
        // another user's index doc via a forged id — return early on mismatch.
        if ($userId !== $actorUserId) {
            return;
        }

        // CRYPT-01 (D-02b) / D-02c disclosed plaintext shadow (BLOCKER-2):
        // counterparty_name/description are ciphertext at rest once
        // encryption is enabled — decrypt via the Sync Public codec BEFORE
        // building the search body so FTS5 tokenizes plaintext, never
        // ciphertext. Pass-through no-op when encryption is not enabled.
        $counterparty = is_string($tx->counterparty_name)
            ? $this->codec->decryptValue('transactions', 'counterparty_name', $tx->counterparty_name, $userId, $this->session)['value']
            : '';
        $description = is_string($tx->description)
            ? $this->codec->decryptValue('transactions', 'description', $tx->description, $userId, $this->session)['value']
            : '';

        // Fetch the tax note for this transaction (if any).
        $tag = $connection
            ->table('tax_transaction_tags')
            ->select(['note'])
            ->where('transaction_id', $transactionId)
            ->where('user_id', $userId)
            ->first();

        $note = ($tag !== null && is_string($tag->note))
            ? $this->codec->decryptValue('tax_transaction_tags', 'note', $tag->note, $userId, $this->session)['value']
            : '';

        // Build the denormalized search body.
        // chr(12) = form-feed — not trigram-indexable, avoids cross-field
        // false positives (RESEARCH Assumption A2).
        $newBody = $counterparty.chr(12).$description.chr(12).$note;

        // CR-03: wrap read-old-body + docs-upsert + FTS delete + FTS insert in
        // a single transaction so a partial write cannot leave duplicate FTS
        // postings (ghost matches). The FTS 'delete' is issued whenever a docs
        // row previously existed — NOT only when the old body was non-empty —
        // because an empty-but-indexed prior body would otherwise stack a
        // second posting on the same rowid on the next insert.
        $connection->transaction(function () use ($connection, $transactionId, $userId, $newBody): void {
            $existingDoc = $connection
                ->table('transaction_search_docs')
                ->select(['search_body'])
                ->where('transaction_id', $transactionId)
                ->first();

            $docExisted = $existingDoc !== null;
            $oldBody = ($existingDoc !== null && is_string($existingDoc->search_body))
                ? $existingDoc->search_body
                : '';

            // Upsert the denormalized doc (unique on transaction_id).
            $connection->table('transaction_search_docs')->upsert(
                [
                    'transaction_id' => $transactionId,
                    'user_id' => $userId,
                    'search_body' => $newBody,
                ],
                ['transaction_id'],
                ['user_id', 'search_body'],
            );

            // (a) Delete the old FTS entry whenever a prior doc row existed,
            // using the body that was previously indexed. This is the
            // documented external-content upsert pattern and prevents the
            // index from accumulating duplicate postings on re-index.
            if ($docExisted) {
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
        });
    }

    /**
     * Remove the FTS5 search document for the given transaction.
     *
     * Called when a transaction is permanently deleted so stale FTS entries
     * do not pollute results for future queries.
     */
    public function deleteForTransaction(int $transactionId, int $actorUserId): void
    {
        $connection = $this->db->connection();

        $connection->transaction(function () use ($connection, $transactionId, $actorUserId): void {
            // Read the existing doc — both for the FTS delete body and for the
            // CR-02 ownership check.
            $existingDoc = $connection
                ->table('transaction_search_docs')
                ->select(['user_id', 'search_body'])
                ->where('transaction_id', $transactionId)
                ->first();

            if ($existingDoc === null) {
                return;
            }

            $ownerId = is_numeric($existingDoc->user_id) ? (int) $existingDoc->user_id : 0;
            if ($ownerId !== $actorUserId) {
                // CR-02: never delete another user's index doc.
                return;
            }

            $oldBody = is_string($existingDoc->search_body) ? $existingDoc->search_body : '';

            // Delete from the FTS virtual table first (a docs row existed).
            $connection->statement(
                "INSERT INTO transaction_search_fts(transaction_search_fts, rowid, search_body) VALUES('delete', ?, ?)",
                [$transactionId, $oldBody],
            );

            // Remove the content doc.
            $connection->table('transaction_search_docs')
                ->where('transaction_id', $transactionId)
                ->delete();
        });
    }
}
