<?php

declare(strict_types=1);

namespace Modules\Search\Internal\Services;

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Search\Public\Contracts\SearchIndexWriterContract;
use Psr\Log\LoggerInterface;

// Synchronous writer keeping transaction_search_docs and the FTS5
// table in lockstep. No try/catch swallow — failures bubble so the
// outer import-chunk transaction rolls back cleanly.
final class SearchIndexWriter implements SearchIndexWriterContract
{
    // One alarm per user per process, the shape SensitiveColumnCodec settled
    // on for the same reason: a peer drain into a shut window refuses once per
    // row, and a line each turned the day's log into something nobody reads.
    /** @var array<int, true> */
    private array $refusalsAlarmed = [];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly SearchSourceText $source,
        // A factory, not the session itself: resolving a session builds the
        // encrypter, and this class is reachable from a console command that
        // Artisan constructs merely to list it.
        private readonly SessionFactory $session,
        private readonly SearchIndexRepairQueue $repairs,
        private readonly Clock $clock,
        private readonly LoggerInterface $log,
    ) {}

    public function upsertForTransaction(int $transactionId, int $actorUserId): void
    {
        $connection = $this->db->connection();

        $tx = $connection
            ->table('transactions')
            ->select(['id', 'user_id', 'counterparty_name', 'description'])
            ->where('id', $transactionId)
            ->first();

        // Retired, not merely skipped: a repair coordinate whose transaction
        // has since been deleted is spent, and leaving it would keep a drained
        // queue reporting work forever.
        if ($tx === null) {
            $this->repairs->retire($actorUserId, $transactionId);

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
        $newBody = $this->bodyFor(
            $connection,
            ['counterparty_name' => $tx->counterparty_name, 'description' => $tx->description],
            $transactionId,
            $userId,
            ($this->session)(),
        );

        if ($newBody === null) {
            $this->deferUntilReadable($transactionId, $userId);

            return;
        }

        $this->writeDoc($connection, $transactionId, $userId, $newBody);
        $this->repairs->retire($userId, $transactionId);
    }

    // Null when a source column is ciphertext this process holds no key for.
    // The body is the ONLY searchable copy of three sealed columns, so one
    // built from what a keyless drain got back overwrites the words with
    // nothing and answers "no such transaction" over a ledger that has them.
    /**
     * @param  array{counterparty_name: mixed, description: mixed}  $stored
     */
    private function bodyFor(
        ConnectionInterface $connection,
        array $stored,
        int $transactionId,
        int $userId,
        Session $session,
    ): ?string {
        $counterparty = $this->source->read('transactions', 'counterparty_name', $stored['counterparty_name'], $userId, $session);
        $description = $this->source->read('transactions', 'description', $stored['description'], $userId, $session);

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

        $note = $this->source->read('tax_transaction_tags', 'note', $tag->note ?? null, $userId, $session);

        if ($counterparty === null || $description === null || $note === null) {
            return null;
        }

        return SearchDocumentBody::join($counterparty, $description, $note);
    }

    // Leaves the stored doc exactly as it was — a stale body still finds the
    // row, an emptied one finds nothing — and records the coordinate so the
    // next process holding a key rebuilds it.
    private function deferUntilReadable(int $transactionId, int $userId): void
    {
        $this->repairs->request($userId, $transactionId, $this->clock->now()->toDateTimeString());

        if (isset($this->refusalsAlarmed[$userId])) {
            return;
        }

        $this->refusalsAlarmed[$userId] = true;
        $this->log->warning(
            'SearchIndexWriter: refused to index a transaction whose sealed columns this process cannot read.',
            ['userId' => $userId, 'transactionId' => $transactionId],
        );
    }

    // Wraps read-old-body + docs-upsert + FTS delete + FTS insert
    // in one transaction so a partial write never leaves duplicate
    // postings; 'delete' fires whenever a docs row previously
    // existed, not only when the old body was non-empty.
    private function writeDoc(ConnectionInterface $connection, int $transactionId, int $userId, string $newBody): void
    {
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

        $this->repairs->retire($actorUserId, $transactionId);
    }
}
