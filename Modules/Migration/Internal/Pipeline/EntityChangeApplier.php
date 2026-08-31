<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Pipeline;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Ledger\Public\Services\FingerprintComposer;
use Modules\Ledger\Public\ValueObjects\TransactionAmount;
use Modules\Migration\Internal\Enums\MigrationEntityType;
use Modules\Migration\Internal\Services\SourceMapWriter;
use Modules\Migration\Internal\ValueObjects\SourceMapKey;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Psr\Log\LoggerInterface;
use stdClass;

final readonly class EntityChangeApplier
{
    use CoercesScalars;

    public function __construct(
        private DatabaseManager $db,
        private SourceMapWriter $sourceMapWriter,
        private FingerprintComposer $fingerprints,
        private LoggerInterface $logger,
        private SensitiveColumnCodec $codec,
        private SessionFactory $session,
    ) {}

    /**
     * @param  array<string, string|int|float|bool|null>  $fields
     */
    public function apply(User $user, string $sourceProduct, string $entityType, string $sourceExternalId, array $fields): bool
    {
        $table = match ($entityType) {
            MigrationEntityType::Category->value => 'categories',
            MigrationEntityType::Account->value => 'accounts',
            MigrationEntityType::Transaction->value => 'transactions',
            default => null,
        };

        $beatraxId = $table === null
            ? null
            : $this->sourceMapWriter->resolve($user, new SourceMapKey($sourceProduct, $entityType, $sourceExternalId));

        if ($table === null || $beatraxId === null) {
            return false;
        }

        if ($entityType === MigrationEntityType::Transaction->value && array_key_exists('amount_minor', $fields)) {
            $newAmountMinor = $fields['amount_minor'];
            if (! (is_int($newAmountMinor) && $this->applyTransactionAmount($user, $beatraxId, $newAmountMinor))) {
                return false;
            }
        } else {
            // A reconciled transactions.description must never land as plaintext
            // in an at-rest-encrypted column.
            $storedFields = $this->codec->encryptAttrs($table, $fields, $user->id, ($this->session)());

            $this->db->connection()->table($table)
                ->where('id', $beatraxId)
                ->where('user_id', $user->id)
                ->update($storedFields);
        }

        $this->sourceMapWriter->record(
            $user,
            new SourceMapKey($sourceProduct, $entityType, $sourceExternalId),
            self::beatraxEntityType($entityType),
            $beatraxId,
            $fields,
        );

        return true;
    }

    public function advanceBaseline(User $user, string $sourceProduct, string $entityType, ?string $sourceExternalId, string $fieldName, string|int $value): void
    {
        if ($sourceExternalId === null) {
            return;
        }

        $key = new SourceMapKey($sourceProduct, $entityType, $sourceExternalId);

        $beatraxId = $this->sourceMapWriter->resolve($user, $key);
        if ($beatraxId === null) {
            return;
        }

        $this->sourceMapWriter->record(
            $user,
            $key,
            self::beatraxEntityType($entityType),
            $beatraxId,
            [$fieldName => $value],
        );
    }

    public function applyTransactionAmount(User $user, int $transactionId, int $newAmountMinor): bool
    {
        // amount_minor is part of the fingerprint tuple, so the recomputed
        // fingerprint must land in the same UPDATE as the amount itself. The
        // settled pair and its rate travel with it too: they are the figure
        // every balance, budget, forecast and report sums.
        $connection = $this->db->connection();

        /** @var stdClass|null $row */
        $row = $connection->table('transactions')
            ->where('id', $transactionId)
            ->where('user_id', $user->id)
            ->first();

        if ($row === null) {
            return false;
        }

        $amount = (new TransactionAmount(
            self::toInt($row->amount_minor),
            self::toString($row->currency),
            self::toInt($row->settled_amount_minor),
            self::toString($row->settled_currency),
            self::toStringOrNull($row->fx_rate_used),
        ))->withAmountMinor($newAmountMinor);

        $canonical = new CanonicalTransaction(
            userId: $user->id,
            accountId: self::toInt($row->account_id),
            type: self::toString($row->type),
            postedAt: CarbonImmutable::parse(self::toString($row->posted_at)),
            bookedAt: CarbonImmutable::parse(self::toString($row->booked_at)),
            valueDate: CarbonImmutable::parse(self::toString($row->value_date)),
            amountMinor: $amount->amountMinor,
            currency: $amount->currency,
            settledAmountMinor: $amount->settledAmountMinor,
            settledCurrency: $amount->settledCurrency,
            counterpartyName: $row->counterparty_name !== null ? self::toString($row->counterparty_name) : null,
            counterpartyIban: $row->counterparty_iban !== null ? self::toString($row->counterparty_iban) : null,
            counterpartyNormalized: self::toString($row->counterparty_normalized),
            normalizationVersion: self::toInt($row->normalization_version),
            description: $row->description !== null ? self::toString($row->description) : null,
            categoryId: $row->category_id !== null ? self::toInt($row->category_id) : null,
            sourceFormat: self::toString($row->source_format),
            importRunId: self::toInt($row->import_run_id),
            sourceRowIndex: self::toInt($row->source_row_index),
            sourceRef: $row->source_ref !== null ? self::toString($row->source_ref) : null,
        );

        $fingerprint = $this->fingerprints->compose($canonical);

        try {
            $connection->table('transactions')
                ->where('id', $transactionId)
                ->where('user_id', $user->id)
                ->update($amount->toColumns() + ['fingerprint' => $fingerprint]);
        } catch (QueryException $e) {
            // Only a fingerprint-uniqueness violation is a benign collision;
            // reclassifying any other QueryException would mask a real failure.
            $this->logger->warning('EntityChangeApplier: applyTransactionAmount() query failed.', [
                'transaction_id' => $transactionId,
                'user_id' => $user->id,
                'is_fingerprint_collision' => self::isFingerprintUniqueViolation($e),
                ...SafeExceptionContext::describe($e),
            ]);

            if (! self::isFingerprintUniqueViolation($e)) {
                throw $e;
            }

            return false;
        }

        return true;
    }

    private static function isFingerprintUniqueViolation(QueryException $e): bool
    {
        // SQLSTATE 23000 alone could come from an unrelated constraint, so the
        // message must also name a column/index this UPDATE could actually hit.
        if ((string) $e->getCode() !== '23000') {
            return false;
        }

        $message = $e->getMessage();

        return str_contains($message, 'transactions.fingerprint')
            || str_contains($message, 'transactions.amount_minor')
            || str_contains($message, 'transactions_fingerprint_uq')
            || str_contains($message, 'transactions_fingerprint_sha_uq');
    }

    private static function beatraxEntityType(string $entityType): string
    {
        return match ($entityType) {
            MigrationEntityType::BudgetAssignment->value => 'envelope_assignment',
            default => $entityType,
        };
    }
}
