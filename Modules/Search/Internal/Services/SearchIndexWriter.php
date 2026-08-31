<?php

declare(strict_types=1);

namespace Modules\Search\Internal\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Search\Public\Contracts\SearchIndexWriterContract;
use Modules\Sync\Public\Services\SensitiveColumnCodec;

// Synchronous writer keeping transaction_search_docs and the FTS5
// table in lockstep. No try/catch swallow — failures bubble so the
// outer import-chunk transaction rolls back cleanly.
final readonly class SearchIndexWriter implements SearchIndexWriterContract
{
    public function __construct(
        private DatabaseManager $db,
        private SensitiveColumnCodec $codec,
        // A factory, not the session itself: resolving a session builds the
        // encrypter, and this class is reachable from a console command that
        // Artisan constructs merely to list it.
        private SessionFactory $session,
    ) {}

    public function upsertForTransaction(int $transactionId, int $actorUserId): void
    {
        $connection = $this->db->connection();

        $tx = $connection
            ->table('transactions')
            ->select(['id', 'user_id', 'counterparty_name', 'description'])
            ->where('id', $transactionId)
            ->first();

        if ($tx === null) {
            return;
        }

        $userId = is_numeric($tx->user_id) ? (int) $tx->user_id : 0;

        // Refuse to (re)index a transaction the actor does not own — a
        // caller can never rebuild another user's index doc via a
        // forged id.
        if ($userId !== $actorUserId) {
            return;
        }

        // Resolved once per write, and only here — the decrypt calls below
        // are the sole reason this class needs a session at all.
        $session = ($this->session)();

        // counterparty_name/description are ciphertext at rest once
        // encryption is enabled — decrypt via the Sync codec BEFORE
        // building the search body so FTS5 tokenizes plaintext, never
        // ciphertext.
        $counterparty = is_string($tx->counterparty_name)
            ? $this->codec->decryptValue('transactions', 'counterparty_name', $tx->counterparty_name, $userId, $session)['value']
            : '';
        $description = is_string($tx->description)
            ? $this->codec->decryptValue('transactions', 'description', $tx->description, $userId, $session)['value']
            : '';

        // The whole-transaction tag, named rather than left to scan order: a
        // split leg's tag matches the same transaction_id and carries no note,
        // so which row `first()` returned decided whether the note was indexed.
        $tag = $connection
            ->table('tax_transaction_tags')
            ->select(['note'])
            ->where('transaction_id', $transactionId)
            ->where('user_id', $userId)
            ->whereNull('transaction_split_id')
            ->first();

        $note = ($tag !== null && is_string($tag->note))
            ? $this->codec->decryptValue('tax_transaction_tags', 'note', $tag->note, $userId, $session)['value']
            : '';

        $newBody = SearchDocumentBody::join($counterparty, $description, $note);

        // Wraps read-old-body + docs-upsert + FTS delete + FTS insert
        // in one transaction so a partial write never leaves duplicate
        // postings; 'delete' fires whenever a docs row previously
        // existed, not only when the old body was non-empty.
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

            $connection->table('transaction_search_docs')->upsert(
                [
                    'transaction_id' => $transactionId,
                    'user_id' => $userId,
                    'search_body' => $newBody,
                ],
                ['transaction_id'],
                ['user_id', 'search_body'],
            );

            if ($docExisted) {
                $connection->statement(
                    "INSERT INTO transaction_search_fts(transaction_search_fts, rowid, search_body) VALUES('delete', ?, ?)",
                    [$transactionId, $oldBody],
                );
            }

            $connection->statement(
                'INSERT INTO transaction_search_fts(rowid, search_body) VALUES(?, ?)',
                [$transactionId, $newBody],
            );
        });
    }

    public function deleteForTransaction(int $transactionId, int $actorUserId): void
    {
        $connection = $this->db->connection();

        $connection->transaction(function () use ($connection, $transactionId, $actorUserId): void {
            $existingDoc = $connection
                ->table('transaction_search_docs')
                ->select(['user_id', 'search_body'])
                ->where('transaction_id', $transactionId)
                ->first();

            if ($existingDoc === null) {
                return;
            }

            // Ownership mismatch — never delete another user's index
            // doc via a forged transaction id.
            $ownerId = is_numeric($existingDoc->user_id) ? (int) $existingDoc->user_id : 0;
            if ($ownerId !== $actorUserId) {
                return;
            }

            $oldBody = is_string($existingDoc->search_body) ? $existingDoc->search_body : '';

            $connection->statement(
                "INSERT INTO transaction_search_fts(transaction_search_fts, rowid, search_body) VALUES('delete', ?, ?)",
                [$transactionId, $oldBody],
            );

            $connection->table('transaction_search_docs')
                ->where('transaction_id', $transactionId)
                ->delete();
        });
    }
}
