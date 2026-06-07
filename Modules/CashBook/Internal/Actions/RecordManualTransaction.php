<?php

declare(strict_types=1);

namespace Modules\CashBook\Internal\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Import\Public\Enums\PaymentType;
use Modules\Ledger\Public\Contracts\RecordsTransactions;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Ledger\Public\Services\FingerprintComposer;

/**
 * Records a hand-entered (typically cash) transaction into the SAME canonical
 * ledger pipeline imports use — so a manual entry categorises, recur-detects,
 * and reports exactly like an imported one. It is NOT a special-cased ledger
 * row: it flows through RecordsTransactions, which composes the fingerprint,
 * insert-or-ignores, and dispatches TransactionImported.
 *
 * The entry hangs off two synthetic, find-or-created per-user fixtures: a single
 * "Cash" account (kind=cash) and a single "manual" import_run. A random
 * source_ref per entry keeps two genuinely-distinct identical cash spends (e.g.
 * two €3 coffees on the same day) from colliding on the transaction fingerprint.
 */
final class RecordManualTransaction
{
    /** Max bookedAt-bump retries to dodge a same-second fingerprint collision. */
    private const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly RecordsTransactions $record,
        private readonly FingerprintComposer $fingerprints,
        private readonly Clock $clock,
    ) {}

    public function __invoke(
        User $user,
        string $direction,
        int $amountMinor,
        CarbonImmutable $date,
        string $counterparty,
        ?int $categoryId = null,
        ?string $description = null,
    ): void {
        $magnitude = abs($amountMinor);
        $signed = $direction === 'income' ? $magnitude : -$magnitude;
        $type = $direction === 'income' ? 'income' : 'expense';

        $accountId = $this->cashAccountId($user);
        $importRunId = $this->manualRunId($user);
        $counterpartyName = trim($counterparty) !== '' ? trim($counterparty) : 'Cash';

        $counterpartyNormalized = $this->fingerprints->normalize($counterpartyName);

        // postedAt/valueDate are the transaction's own date (the user picks it,
        // so the entry lands in the right period). bookedAt is when it entered
        // the ledger.
        //
        // De-duplication is the subtle part: both transaction-fingerprint unique
        // indexes (the column tuple AND the SHA) key on (…, booked_at, …) at
        // SECOND precision and EXCLUDE source_ref, so two genuinely-distinct
        // identical cash spends (same counterparty/amount/date) recorded in the
        // same second hash identically and the ledger's insertOrIgnore would
        // silently drop the second. RecordsTransactions reports how many rows it
        // actually inserted, so we bump bookedAt by a second and retry until the
        // entry lands — guaranteeing a manual entry is never silently lost,
        // while a true same-instant double-submit still collapses harmlessly.
        $now = $this->clock->now();
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $canonical = new CanonicalTransaction(
                userId: $user->id,
                accountId: $accountId,
                type: $type,
                postedAt: $date,
                bookedAt: $now->addSeconds($attempt),
                valueDate: $date,
                amountMinor: $signed,
                currency: 'EUR',
                settledAmountMinor: $signed,
                settledCurrency: 'EUR',
                fxRateUsed: null,
                counterpartyName: $counterpartyName,
                counterpartyIban: null,
                counterpartyNormalized: $counterpartyNormalized,
                normalizationVersion: $this->fingerprints->version(),
                description: $description,
                categoryId: $categoryId,
                sourceFormat: 'manual',
                importRunId: $importRunId,
                sourceRowIndex: 0,
                sourceRef: 'manual-'.bin2hex(random_bytes(8)),
                rawPayload: null,
                autoCategoryProvenance: null,
                paymentType: PaymentType::Cash,
            );

            if (($this->record)([$canonical], $user)->inserted > 0) {
                return;
            }
        }
    }

    private function cashAccountId(User $user): int
    {
        $now = $this->clock->now()->toDateTimeString();

        return $this->findOrCreate('accounts', ['user_id' => $user->id, 'kind' => 'cash'], [
            'user_id' => $user->id,
            'name' => 'Cash',
            'slug' => 'cash-'.$user->id,
            'kind' => 'cash',
            'iban' => 'CASH'.str_pad((string) $user->id, 12, '0', STR_PAD_LEFT),
            'default_currency' => 'EUR',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function manualRunId(User $user): int
    {
        $now = $this->clock->now()->toDateTimeString();

        return $this->findOrCreate('import_runs', ['user_id' => $user->id, 'source_format' => 'manual'], [
            'user_id' => $user->id,
            'source_format' => 'manual',
            'raw_file_path' => 'manual',
            'sha256' => str_repeat('0', 64),
            'uploaded_at' => $now,
            'status' => 'confirmed',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Find a row's id by $match, or insert $attributes and return the new id.
     * Re-selects on a unique-constraint violation so a concurrent double-submit
     * (two adds racing to create the singleton Cash account / manual run) never
     * surfaces as a 500.
     *
     * @param  array<string, mixed>  $match
     * @param  array<string, mixed>  $attributes
     */
    private function findOrCreate(string $table, array $match, array $attributes): int
    {
        $connection = $this->db->connection();
        $find = static fn (): mixed => $connection->table($table)->where($match)->value('id');

        $existing = $find();
        if (is_numeric($existing)) {
            return (int) $existing;
        }

        try {
            return $connection->table($table)->insertGetId($attributes);
        } catch (QueryException $e) {
            $retry = $find();
            if (is_numeric($retry)) {
                return (int) $retry;
            }
            throw $e;
        }
    }
}
