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

// Records a hand-entered transaction through the same canonical pipeline
// imports use, so it categorises/recur-detects/reports identically. It hangs
// off two synthetic per-user fixtures (a "Cash" account, a "manual"
// import_run); a random source_ref avoids same-day fingerprint collisions.
/**
 * @see RecordsTransactions
 */
final class RecordManualTransaction
{
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

        // postedAt/valueDate are the transaction's own date; bookedAt is when
        // it entered the ledger. Both fingerprint indexes key on booked_at at
        // second precision, so two identical same-second cash spends would
        // otherwise collide; bump bookedAt by a second and retry until it lands.
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

    // Re-selects on a unique-constraint violation so a concurrent double-submit
    // (two adds racing to create the singleton Cash account / manual run)
    // never surfaces as a 500.
    /**
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
