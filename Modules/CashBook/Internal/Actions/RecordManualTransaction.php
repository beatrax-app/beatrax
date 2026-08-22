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
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Enums\Direction;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Ledger\Public\Services\AccountSlugResolver;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Ledger\Public\Services\CounterpartyKey;
use Modules\Ledger\Public\Services\FingerprintComposer;

// Routed through the same canonical pipeline imports use, so a hand-entered
// row categorises, recur-detects and reports identically to an imported one.
/**
 * @see RecordsTransactions
 */
final class RecordManualTransaction
{
    private const MAX_ATTEMPTS = 5;

    private const string ACCOUNT_NAME = 'Cash';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly RecordsTransactions $record,
        private readonly FingerprintComposer $fingerprints,
        private readonly Clock $clock,
        private readonly BaseCurrency $baseCurrency,
        private readonly CounterpartyKey $counterpartyKey,
        private readonly AccountSlugResolver $accountSlugs,
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
        $isIncome = $direction === Direction::Income->value;
        $signed = $isIncome ? $magnitude : -$magnitude;
        $type = $isIncome ? TransactionType::Income->value : TransactionType::Expense->value;

        $accountId = $this->cashAccountId($user);
        $importRunId = $this->manualRunId($user);
        $counterpartyName = trim($counterparty) !== '' ? trim($counterparty) : 'Cash';

        $counterpartyNormalized = $this->counterpartyKey->forName($counterpartyName, $user->id);

        // Both fingerprint indexes key booked_at at second precision, so two
        // identical same-second cash spends collide.
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
                currency: $this->baseCurrency->code(),
                settledAmountMinor: $signed,
                settledCurrency: $this->baseCurrency->code(),
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

    // The slug comes from the ledger's own walk rather than from the user id:
    // `cash-<id>` is a spelling the walk also hands out, so a user whose id is
    // 2 and who already owns an account named "Cash 2" collided on
    // unique(user_id, slug) and lost the cash book outright.
    private function cashAccountId(User $user): int
    {
        $now = $this->clock->now()->toDateTimeString();

        return $this->findOrCreate('accounts', ['user_id' => $user->id, 'kind' => AccountKind::Cash->value], [
            'user_id' => $user->id,
            'name' => self::ACCOUNT_NAME,
            'slug' => $this->accountSlugs->resolveUnique($user->id, self::ACCOUNT_NAME),
            'kind' => AccountKind::Cash->value,
            'iban' => 'CASH'.str_pad((string) $user->id, 12, '0', STR_PAD_LEFT),
            'default_currency' => $this->baseCurrency->code(),
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

    // Re-selects on a unique violation, so two adds racing to create the
    // singleton Cash account never surface as a 500.
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
