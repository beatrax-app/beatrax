<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Import\Public\Events\TransactionImported;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Contracts\RecordsTransactions;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Ledger\Public\Dto\RecordResult;
use Modules\Ledger\Public\Events\TransactionBatchImported;
use Modules\Ledger\Public\Services\FingerprintComposer;
use Modules\Sync\Public\Services\SensitiveColumnCodec;

/**
 * Persists a batch of canonical transactions in bounded, independently-
 * committing chunks. This is NO LONGER whole-file-atomic: a full-year import
 * must not run as one unbounded in-memory DB transaction in the web request,
 * so the batch is sliced into `CHUNK_SIZE` rows and each chunk is committed in
 * its own DB transaction.
 *
 * The new guarantee is idempotent + resumable rather than all-or-nothing:
 *  - A row that fails the type-validation pre-check rolls back only its own
 *    chunk; chunks committed before it stay committed.
 *  - Rows whose fingerprint already exists are silently dropped by
 *    `insertOrIgnore` — the DB-layer idempotency proof — so re-running the
 *    same source after a partial failure is non-duplicating: already-committed
 *    rows are skipped and only the not-yet-stored remainder lands.
 *
 * Every row must carry a non-null `userId`. SQLite treats NULL as distinct in
 * UNIQUE indexes, so a row written with `user_id = NULL` would slip past the
 * composite UNIQUE on `(user_id, account_id, posted_at, …)` on a re-import.
 * The action rejects null-user rows before any DB write so the idempotency
 * guarantee holds for every persisted row.
 *
 * `created_at` / `updated_at` are stamped here (not inside the DTO) so the
 * value comes from the injected Clock and remains pinnable from tests.
 *
 * For every row that `insertOrIgnore` actually persists (effected === 1) a
 * `TransactionImported` event is dispatched synchronously through the
 * constructor-injected Dispatcher. The dispatch sits inside the row's own
 * chunk transaction so cross-module listeners (e.g., transfer-pair detection)
 * observe just-inserted partner rows within the same chunk's atomic frame.
 * Duplicates never produce an event.
 */
final class RecordTransactions implements RecordsTransactions
{
    /**
     * Maximum rows persisted per DB transaction. Bounds the size of any single
     * transaction/in-memory unit so a large import is broken into
     * independently-committing slices rather than one giant transaction.
     */
    private const CHUNK_SIZE = 500;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly FingerprintComposer $fingerprints,
        private readonly Clock $clock,
        private readonly Dispatcher $events,
        private readonly SensitiveColumnCodec $codec,
        private readonly Session $session,
    ) {}

    public function __invoke(iterable $canonical, User $user): RecordResult
    {
        $inserted = 0;
        $duplicates = 0;
        /** @var list<string> $sourceFormats */
        $sourceFormats = [];

        // Buffer the (possibly lazy) iterable into bounded chunks and commit
        // each chunk on its own. iterator_to_array would force the whole batch
        // into memory at once; chunking keeps the working set bounded.
        $chunk = [];
        foreach ($canonical as $row) {
            $chunk[] = $row;
            if (count($chunk) >= self::CHUNK_SIZE) {
                $this->persistChunk($chunk, $user, $inserted, $duplicates, $sourceFormats);
                $chunk = [];
            }
        }

        // Flush the trailing partial chunk.
        if ($chunk !== []) {
            $this->persistChunk($chunk, $user, $inserted, $duplicates, $sourceFormats);
        }

        // Batch-altitude announcement (D-22): dispatched exactly ONCE per
        // call, AFTER every chunk transaction above has already committed,
        // and only when at least one row actually landed. See
        // TransactionBatchImported's docblock for the emit-after-commit
        // contract this satisfies for free.
        if ($inserted > 0) {
            $distinctFormats = array_values(array_unique($sourceFormats));
            sort($distinctFormats);

            $this->events->dispatch(new TransactionBatchImported(
                userId: $user->id,
                insertedCount: $inserted,
                sourceFormats: $distinctFormats,
            ));
        }

        return new RecordResult(inserted: $inserted, duplicates: $duplicates);
    }

    /**
     * Persist one bounded chunk in its own DB transaction, folding the chunk's
     * insert/duplicate counts into the running totals. A failing row rolls back
     * only this chunk; prior chunks remain committed.
     *
     * @param  list<CanonicalTransaction>  $chunk
     * @param  list<string>  $sourceFormats
     */
    private function persistChunk(array $chunk, User $user, int &$inserted, int &$duplicates, array &$sourceFormats): void
    {
        $this->db->connection()->transaction(function () use ($chunk, $user, &$inserted, &$duplicates, &$sourceFormats): void {
            $now = $this->clock->now()->toDateTimeString();
            foreach ($chunk as $row) {
                if ($row->userId === null) {
                    throw new InvalidArgumentException('CanonicalTransaction.userId must not be null when recording transactions.');
                }
                if (! in_array($row->type, Transaction::TYPES, true)) {
                    throw new InvalidArgumentException("Invalid transaction type: '{$row->type}'");
                }

                // CRITICAL (CRYPT-01 direct-write hook, 14-RESEARCH Pitfall 2):
                // the de-dup fingerprint is composed from the plaintext DTO
                // ($row), NEVER from the (possibly-encrypted) $attrs below —
                // re-import idempotency must be identical whether or not
                // encryption is enabled.
                $fingerprint = $this->fingerprints->compose($row);
                $attrs = $row->toAttributes() + [
                    'fingerprint' => $fingerprint,
                    'fingerprint_version' => $this->fingerprints->version(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                // Encrypt the D-02b sensitive content columns (description,
                // counterparty_name, counterparty_iban, raw_payload) under
                // the current GDK epoch before the row ever touches disk.
                // Pass-through (no-op) when encryption is not enabled for
                // this user. amount_minor/settled_amount_minor/fx_rate_used
                // are never touched — D-02a excludes them so SQL SUM()/
                // GROUP BY keeps working (Pitfall 1).
                $attrs = $this->codec->encryptAttrs('transactions', $attrs, $row->userId, $this->session);

                $effected = Transaction::insertOrIgnore($attrs);
                if ($effected === 1) {
                    $inserted++;
                    $sourceFormats[] = $row->sourceFormat;

                    /** @var Transaction $persisted */
                    $persisted = Transaction::query()
                        ->where('user_id', $row->userId)
                        ->where('fingerprint', $fingerprint)
                        ->firstOrFail();

                    $this->events->dispatch(new TransactionImported(
                        transaction: $persisted,
                        user: $user,
                    ));
                } else {
                    $duplicates++;
                }
            }
        });
    }
}
