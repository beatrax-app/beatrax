<?php

declare(strict_types=1);

namespace Modules\CashBook\Internal\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\CashBook\Internal\Services\ManualEntryAnchors;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Counterparties\Public\Pipeline\ResolvesCounterparties;
use Modules\Import\Public\Enums\PaymentType;
use Modules\Import\Public\Enums\SyntheticSourceFormat;
use Modules\Ledger\Public\Contracts\RecordsTransactions;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Ledger\Public\Enums\Direction;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Ledger\Public\Services\CounterpartyKey;
use Modules\Ledger\Public\Services\FingerprintComposer;

// Routed through the same canonical pipeline imports use, so a hand-entered
// row categorises, recur-detects, resolves its counterparty and reports
// identically to an imported one.
/**
 * @see RecordsTransactions
 */
final readonly class RecordManualTransaction
{
    private const int MAX_ATTEMPTS = 5;

    public function __construct(
        private DatabaseManager $db,
        private RecordsTransactions $record,
        private FingerprintComposer $fingerprints,
        private Clock $clock,
        private CounterpartyKey $counterpartyKey,
        private ManualEntryAnchors $anchors,
        private ResolvesCounterparties $resolveCounterparty,
    ) {}

    // False when nothing was written, so the page can say so. It used to end
    // the retry loop on a `return` of nothing, and the caller toasted "Cash
    // entry added." either way: six identical coffees on one day produced
    // twelve of that sentence and six rows.
    public function __invoke(
        User $user,
        string $direction,
        int $amountMinor,
        CarbonImmutable $date,
        string $counterparty,
        ?int $categoryId = null,
        ?string $description = null,
    ): bool {
        $magnitude = abs($amountMinor);
        $isIncome = $direction === Direction::Income->value;
        $signed = $isIncome ? $magnitude : -$magnitude;
        $type = $isIncome ? TransactionType::Income->value : TransactionType::Expense->value;

        $accountId = $this->anchors->accountIdFor($user);
        $importRunId = $accountId === null ? null : $this->anchors->runIdFor($user);

        // Nothing to hang the entry on. Reported as "not recorded" rather than
        // thrown, because the page keeps the reader's fields on a false and
        // raw SQL is not an answer a reader can act on.
        if ($accountId === null || $importRunId === null) {
            return false;
        }

        $currency = $this->anchors->currencyFor($accountId, $user);

        // An entry the reader named nobody on has no counterparty, rather than
        // one the app invents for them: a stand-in name would be stored as
        // data, and would mint a counterparty row that spend analysis, triage
        // and merchant matching would then treat as somewhere they shop.
        $counterpartyName = trim($counterparty) !== '' ? trim($counterparty) : null;

        $counterpartyNormalized = $this->counterpartyKey->forName($counterpartyName, $user->id);

        $bookedAt = $this->enteredAt($date);
        $ordinal = $this->nextFreeOrdinal($user, $accountId, $date, $bookedAt, $signed, $currency, $counterpartyNormalized);
        $counterpartyId = null;

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $canonical = new CanonicalTransaction(
                userId: $user->id,
                accountId: $accountId,
                type: $type,
                postedAt: $date,
                bookedAt: $bookedAt,
                valueDate: $date,
                amountMinor: $signed,
                currency: $currency,
                settledAmountMinor: $signed,
                settledCurrency: $currency,
                counterpartyName: $counterpartyName,
                counterpartyIban: null,
                counterpartyNormalized: $counterpartyNormalized,
                normalizationVersion: $this->fingerprints->version(),
                description: $description,
                categoryId: $categoryId,
                sourceFormat: SyntheticSourceFormat::Manual->value,
                importRunId: $importRunId,
                sourceRowIndex: 0,
                sourceRef: 'manual-'.bin2hex(random_bytes(8)),
                rawPayload: null,
                autoCategoryProvenance: null,
                paymentType: PaymentType::Cash,
                counterpartyId: $counterpartyId,
                occurrenceOrdinal: $ordinal + $attempt,
            );

            // Resolved once, on the first attempt: the retries differ only in
            // which occurrence they claim, and the stage's upsert is a write.
            if ($attempt === 0) {
                $canonical = $this->resolveCounterparty->run($canonical, $user);
                $counterpartyId = $canonical->counterpartyId;
            }

            if (($this->record)([$canonical], $user)->inserted > 0) {
                return true;
            }
        }

        return false;
    }

    // The day is the reader's, for the tax year; the time of day is the second
    // they typed it. Both are facts, and the second is the only one of them two
    // devices holding the same entry disagree about — so it is what keeps a
    // coffee typed on the phone from merging into one typed on the desktop.
    private function enteredAt(CarbonImmutable $date): CarbonImmutable
    {
        return $date->startOfDay()->addSeconds($this->clock->now()->secondsSinceMidnight());
    }

    // Read off the ledger rather than counted within a file, because a typed
    // entry has no file: the reader saying "coffee" twice is two facts, and
    // nothing but this ledger records that they already said it once.
    /**
     * @link ../../../../.docs/architecture/ingestion-pipeline.md#the-occurrence-ordinal
     */
    private function nextFreeOrdinal(
        User $user,
        int $accountId,
        CarbonImmutable $date,
        CarbonImmutable $bookedAt,
        int $signedMinor,
        string $currency,
        string $counterpartyNormalized,
    ): int {
        $highest = $this->db->connection()->table('transactions')
            ->where('user_id', $user->id)
            ->where('account_id', $accountId)
            ->where('posted_at', $date->toDateString())
            ->where('booked_at', $bookedAt->toDateTimeString())
            ->where('amount_minor', $signedMinor)
            ->where('currency', $currency)
            ->where('counterparty_normalized', $counterpartyNormalized)
            ->max('occurrence_ordinal');

        return is_numeric($highest) ? (int) $highest + 1 : 0;
    }
}
