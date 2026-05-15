<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Import\Public\Events\TransactionImported;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Contracts\RecordsTransactions;
use Modules\Ledger\Public\Dto\RecordResult;
use Modules\Ledger\Public\Services\FingerprintComposer;

/**
 * Persists a batch of canonical transactions atomically. The single DB
 * transaction is the rollback boundary: if any row in the batch fails the
 * type-validation pre-check, the whole batch is rolled back so a half-imported
 * file never lands. Rows whose fingerprint already exists are silently dropped
 * by `insertOrIgnore` — the DB-layer idempotency proof.
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
 * constructor-injected Dispatcher. The dispatch sits inside the existing
 * outer DB transaction so cross-module listeners (e.g., transfer-pair
 * detection) observe just-inserted partner rows within the same import
 * batch's atomic frame. Duplicates never produce an event.
 */
final class RecordTransactions implements RecordsTransactions
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly FingerprintComposer $fingerprints,
        private readonly Clock $clock,
        private readonly Dispatcher $events,
    ) {}

    public function __invoke(iterable $canonical, User $user): RecordResult
    {
        $inserted = 0;
        $duplicates = 0;

        $this->db->connection()->transaction(function () use ($canonical, $user, &$inserted, &$duplicates): void {
            $now = $this->clock->now()->toDateTimeString();
            foreach ($canonical as $row) {
                if ($row->userId === null) {
                    throw new InvalidArgumentException('CanonicalTransaction.userId must not be null when recording transactions.');
                }
                if (! in_array($row->type, Transaction::TYPES, true)) {
                    throw new InvalidArgumentException("Invalid transaction type: '{$row->type}'");
                }

                $fingerprint = $this->fingerprints->compose($row);
                $attrs = $row->toAttributes() + [
                    'fingerprint' => $fingerprint,
                    'fingerprint_version' => $this->fingerprints->version(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $effected = Transaction::insertOrIgnore($attrs);
                if ($effected === 1) {
                    $inserted++;

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

        return new RecordResult(inserted: $inserted, duplicates: $duplicates);
    }
}
