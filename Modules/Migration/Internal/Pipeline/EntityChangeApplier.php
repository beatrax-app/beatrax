<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Pipeline;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Ledger\Public\Services\FingerprintComposer;
use Modules\Migration\Internal\Services\SourceMapWriter;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Psr\Log\LoggerInterface;
use stdClass;

/**
 * @link ../../../../.docs/features/migration/architecture.md
 */
final class EntityChangeApplier
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly SourceMapWriter $sourceMapWriter,
        private readonly FingerprintComposer $fingerprints,
        private readonly LoggerInterface $logger,
        private readonly SensitiveColumnCodec $codec,
        private readonly Session $session,
    ) {}

    /**
     * @param  array<string, string|int|float|bool|null>  $fields
     */
    public function apply(User $user, string $sourceProduct, string $entityType, string $sourceExternalId, array $fields): bool
    {
        $table = match ($entityType) {
            'category' => 'categories',
            'account' => 'accounts',
            'transaction' => 'transactions',
            default => null,
        };

        if ($table === null) {
            return false;
        }

        $beatraxId = $this->sourceMapWriter->resolve($user, $sourceProduct, $entityType, $sourceExternalId);
        if ($beatraxId === null) {
            return false;
        }

        if ($entityType === 'transaction' && array_key_exists('amount_minor', $fields)) {
            $newAmountMinor = $fields['amount_minor'];
            if (! (is_int($newAmountMinor) && $this->applyTransactionAmount($user, $beatraxId, $newAmountMinor))) {
                return false;
            }
        } else {
            // A reconciled transaction.description must never land as
            // plaintext in an at-rest-encrypted column — see the
            // architecture doc's "Encryption interaction" section for the
            // baseline-snapshot trade-off this implies.
            $storedFields = $this->codec->encryptAttrs($table, $fields, $user->id, $this->session);

            $this->db->connection()->table($table)
                ->where('id', $beatraxId)
                ->where('user_id', $user->id)
                ->update($storedFields);
        }

        $this->sourceMapWriter->record(
            $user,
            $sourceProduct,
            $entityType,
            $sourceExternalId,
            null,
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

        $beatraxId = $this->sourceMapWriter->resolve($user, $sourceProduct, $entityType, $sourceExternalId);
        if ($beatraxId === null) {
            // Defensive: a conflict only ever exists for an already-mapped
            // entity.
            return;
        }

        $this->sourceMapWriter->record(
            $user,
            $sourceProduct,
            $entityType,
            $sourceExternalId,
            null,
            self::beatraxEntityType($entityType),
            $beatraxId,
            [$fieldName => $value],
        );
    }

    public function applyTransactionAmount(User $user, int $transactionId, int $newAmountMinor): bool
    {
        // Recomputes the stored fingerprint in the same statement as the
        // amount_minor update, so the second-layer hashed idempotency guard
        // never drifts from the row's actual content — see the architecture
        // doc for why no decrypt step is needed here for an encrypted user.
        $connection = $this->db->connection();

        /** @var stdClass|null $row */
        $row = $connection->table('transactions')
            ->where('id', $transactionId)
            ->where('user_id', $user->id)
            ->first();

        if ($row === null) {
            return false;
        }

        $canonical = new CanonicalTransaction(
            userId: $user->id,
            accountId: self::toInt($row->account_id),
            type: self::toStr($row->type),
            postedAt: CarbonImmutable::parse(self::toStr($row->posted_at)),
            bookedAt: CarbonImmutable::parse(self::toStr($row->booked_at)),
            valueDate: CarbonImmutable::parse(self::toStr($row->value_date)),
            amountMinor: $newAmountMinor,
            currency: self::toStr($row->currency),
            settledAmountMinor: self::toInt($row->settled_amount_minor),
            settledCurrency: self::toStr($row->settled_currency),
            fxRateUsed: $row->fx_rate_used !== null ? self::toStr($row->fx_rate_used) : null,
            counterpartyName: $row->counterparty_name !== null ? self::toStr($row->counterparty_name) : null,
            counterpartyIban: $row->counterparty_iban !== null ? self::toStr($row->counterparty_iban) : null,
            counterpartyNormalized: self::toStr($row->counterparty_normalized),
            normalizationVersion: self::toInt($row->normalization_version),
            description: $row->description !== null ? self::toStr($row->description) : null,
            categoryId: $row->category_id !== null ? self::toInt($row->category_id) : null,
            sourceFormat: self::toStr($row->source_format),
            importRunId: self::toInt($row->import_run_id),
            sourceRowIndex: self::toInt($row->source_row_index),
            sourceRef: $row->source_ref !== null ? self::toStr($row->source_ref) : null,
        );

        $fingerprint = $this->fingerprints->compose($canonical);

        try {
            $connection->table('transactions')
                ->where('id', $transactionId)
                ->where('user_id', $user->id)
                ->update([
                    'amount_minor' => $newAmountMinor,
                    'fingerprint' => $fingerprint,
                ]);
        } catch (QueryException $e) {
            // Only a genuine fingerprint-uniqueness violation is a benign,
            // expected collision — any OTHER QueryException must NOT be
            // silently reclassified as "it's just a collision", or a real
            // failure would be masked. The raw exception is always logged.
            $this->logger->warning('EntityChangeApplier: applyTransactionAmount() query failed.', [
                'transaction_id' => $transactionId,
                'user_id' => $user->id,
                'is_fingerprint_collision' => self::isFingerprintUniqueViolation($e),
                'exception_message' => $e->getMessage(),
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
        // SQLSTATE 23000 alone is not enough — it could come from an
        // unrelated constraint, so the message must additionally reference
        // one of the two fingerprint columns/indexes this UPDATE could hit
        // (see the architecture doc for the classifier's exact reasoning).
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
            'budget_assignment' => 'envelope_assignment',
            default => $entityType,
        };
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function toStr(mixed $value): string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }
}
