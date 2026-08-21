<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Import\Public\Events\TransactionImported;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Contracts\CapturesTransactionsForSync;
use Modules\Ledger\Public\Contracts\RecordsTransactions;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Ledger\Public\Dto\RecordResult;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Ledger\Public\Events\TransactionBatchImported;
use Modules\Ledger\Public\Services\FingerprintComposer;
use Modules\Sync\Public\Services\SensitiveColumnCodec;

final class RecordTransactions implements RecordsTransactions
{
    private const CHUNK_SIZE = 500;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly FingerprintComposer $fingerprints,
        private readonly Clock $clock,
        private readonly Dispatcher $events,
        private readonly SensitiveColumnCodec $codec,
        private readonly SessionFactory $session,
        private readonly CapturesTransactionsForSync $syncCapture,
    ) {}

    // $captureForSync is the import path's opt-out, and only its: that
    // caller captures the run and its accounts before the transactions, so
    // capturing here as well wrote every imported row twice.
    public function __invoke(iterable $canonical, User $user, bool $captureForSync = true): RecordResult
    {
        /** @var list<int> $insertedIds */
        $insertedIds = [];

        $inserted = 0;
        $duplicates = 0;
        /** @var list<string> $sourceFormats */
        $sourceFormats = [];

        // iterator_to_array would force the whole (possibly lazy) batch into
        // memory, so rows are buffered into chunks committed one at a time.
        $chunk = [];
        foreach ($canonical as $row) {
            $chunk[] = $row;
            if (count($chunk) >= self::CHUNK_SIZE) {
                $this->persistChunk($chunk, $user, $inserted, $duplicates, $sourceFormats, $insertedIds);
                $chunk = [];
            }
        }

        if ($chunk !== []) {
            $this->persistChunk($chunk, $user, $inserted, $duplicates, $sourceFormats, $insertedIds);
        }

        // Once per call, after every chunk transaction above has committed —
        // a listener that queries these rows must not race the write.
        if ($inserted > 0) {
            $distinctFormats = array_values(array_unique($sourceFormats));
            sort($distinctFormats);

            $this->events->dispatch(new TransactionBatchImported(
                userId: $user->id,
                insertedCount: $inserted,
                sourceFormats: $distinctFormats,
            ));

            // Post-commit, and here rather than at each caller: the cash book,
            // e-mail receipts and the migration pipeline all record through
            // this one action, and none of them was covered.
            if ($captureForSync) {
                $this->syncCapture->captureTransactions($insertedIds, $user);
            }
        }

        return new RecordResult(inserted: $inserted, duplicates: $duplicates);
    }

    /**
     * @param  list<CanonicalTransaction>  $chunk
     * @param  list<string>  $sourceFormats
     * @param  list<int>  $insertedIds
     */
    private function persistChunk(array $chunk, User $user, int &$inserted, int &$duplicates, array &$sourceFormats, array &$insertedIds): void
    {
        /** @var list<Transaction> $persistedRows */
        $persistedRows = [];

        $this->db->connection()->transaction(function () use ($chunk, &$inserted, &$duplicates, &$sourceFormats, &$insertedIds, &$persistedRows): void {
            $now = $this->clock->now()->toDateTimeString();
            foreach ($chunk as $row) {
                if ($row->userId === null) {
                    throw new InvalidArgumentException('CanonicalTransaction.userId must not be null when recording transactions.');
                }
                if (TransactionType::tryFrom($row->type) === null) {
                    throw new InvalidArgumentException("Invalid transaction type: '{$row->type}'");
                }

                // Composed from the DTO, never the possibly-encrypted $attrs
                // below. The counterparty key it hashes is already keyed and
                // deterministic; the AEAD columns are not, and a fingerprint
                // over those would differ on every write of the same row.
                $fingerprint = $this->fingerprints->compose($row);
                $attrs = $row->toAttributes() + [
                    'fingerprint' => $fingerprint,
                    'fingerprint_version' => $this->fingerprints->version(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                // Content columns only — amount columns stay plaintext, so SQL
                // SUM()/GROUP BY keeps working.
                $attrs = $this->codec->encryptAttrs('transactions', $attrs, $row->userId, ($this->session)());

                $effected = Transaction::insertOrIgnore($attrs);
                if ($effected === 1) {
                    $inserted++;
                    $sourceFormats[] = $row->sourceFormat;

                    /** @var Transaction $persisted */
                    $persisted = Transaction::query()
                        ->where('user_id', $row->userId)
                        ->where('fingerprint', $fingerprint)
                        ->firstOrFail();

                    $insertedIds[] = $persisted->id;
                    $persistedRows[] = $persisted;
                } else {
                    $duplicates++;
                }
            }
        });

        // After this chunk commits, never inside it. Anomaly, Receipts,
        // Transfers and Search all listen synchronously, so a rollback left the
        // search index and the transfer pairing acting on rows that vanished.
        foreach ($persistedRows as $persisted) {
            $this->events->dispatch(new TransactionImported(
                transaction: $persisted,
                user: $user,
            ));
        }
    }
}
