<?php

declare(strict_types=1);

namespace Modules\Ledger\Internal\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Ledger\Public\Services\FingerprintComposer;
use stdClass;

final readonly class FingerprintRederiveService
{
    use CoercesScalars;

    public function __construct(
        private FingerprintComposer $fingerprints,
        private DatabaseManager $db,
    ) {}

    /**
     * @param  bool  $apply  When true and no collision is detected, the
     *                       UPDATEs are written inside a single DB
     *                       transaction. When false, the call is a
     *                       dry-run: the service reports how many rows
     *                       would be touched but writes nothing.
     */
    public function run(bool $apply): FingerprintRederiveOutcome
    {
        $connection = $this->db->connection();
        $targetVersion = $this->fingerprints->version();

        $rows = $connection->table('transactions')
            ->select([
                'id',
                'user_id',
                'account_id',
                'type',
                'posted_at',
                'booked_at',
                'value_date',
                'amount_minor',
                'currency',
                'settled_amount_minor',
                'settled_currency',
                'fx_rate_used',
                'counterparty_name',
                'counterparty_iban',
                'counterparty_normalized',
                'normalization_version',
                'description',
                'category_id',
                'source_format',
                'import_run_id',
                'source_row_index',
                'source_ref',
            ])
            ->orderBy('id')
            ->get();

        /** @var array<string, int> $seen Maps `${user_id}|${fingerprint}` to the first transactions.id that produced it */
        $seen = [];
        /** @var array<int, string> $updates Maps transactions.id to the new sha256 fingerprint */
        $updates = [];
        /** @var list<array{existing_id:int,colliding_id:int,fingerprint:string}> $collisions */
        $collisions = [];

        foreach ($rows as $row) {
            $existingVersion = self::toInt($row->normalization_version);
            if ($existingVersion >= $targetVersion) {
                continue;
            }

            $canonical = $this->buildCanonicalFromRow($row, $targetVersion);
            $newFingerprint = $this->fingerprints->compose($canonical);
            $userKey = (string) self::toIntOrNull($row->user_id, 0);
            $key = $userKey.'|'.$newFingerprint;
            $rowId = self::toInt($row->id);

            if (isset($seen[$key])) {
                $collisions[] = [
                    'existing_id' => $seen[$key],
                    'colliding_id' => $rowId,
                    'fingerprint' => $newFingerprint,
                ];

                continue;
            }

            $seen[$key] = $rowId;
            $updates[$rowId] = $newFingerprint;
        }

        if ($collisions !== []) {
            return FingerprintRederiveOutcome::collided($targetVersion, $collisions);
        }

        $pendingCount = count($updates);

        return match (true) {
            $pendingCount === 0 => FingerprintRederiveOutcome::noop($targetVersion),
            ! $apply => FingerprintRederiveOutcome::dryRun($targetVersion, $pendingCount),
            default => $this->applyUpdates($connection, $updates, $targetVersion, $pendingCount),
        };
    }

    /**
     * @param  array<int, string>  $updates  Maps transactions.id to the new sha256 fingerprint
     */
    private function applyUpdates(
        ConnectionInterface $connection,
        array $updates,
        int $targetVersion,
        int $pendingCount,
    ): FingerprintRederiveOutcome {
        $connection->transaction(function () use ($connection, $updates, $targetVersion): void {
            foreach ($updates as $id => $newFingerprint) {
                $connection->table('transactions')
                    ->where('id', $id)
                    ->update([
                        'fingerprint' => $newFingerprint,
                        'fingerprint_version' => $targetVersion,
                        'normalization_version' => $targetVersion,
                    ]);
            }
        });

        return FingerprintRederiveOutcome::applied($targetVersion, $pendingCount);
    }

    // ISO date strings round-trip through CarbonImmutable so the composer
    // sees the same value it would for a freshly-built canonical.
    private function buildCanonicalFromRow(stdClass $row, int $targetVersion): CanonicalTransaction
    {
        return new CanonicalTransaction(
            userId: self::toIntOrNull($row->user_id, null),
            accountId: self::toInt($row->account_id),
            type: self::toString($row->type),
            postedAt: CarbonImmutable::parse(self::toString($row->posted_at)),
            bookedAt: CarbonImmutable::parse(self::toString($row->booked_at)),
            valueDate: CarbonImmutable::parse(self::toString($row->value_date)),
            amountMinor: self::toInt($row->amount_minor),
            currency: self::toString($row->currency),
            settledAmountMinor: self::toInt($row->settled_amount_minor),
            settledCurrency: self::toString($row->settled_currency),
            counterpartyName: self::toStringOrNull($row->counterparty_name),
            counterpartyIban: self::toStringOrNull($row->counterparty_iban),
            counterpartyNormalized: self::toString($row->counterparty_normalized),
            normalizationVersion: $targetVersion,
            description: self::toStringOrNull($row->description),
            categoryId: self::toIntOrNull($row->category_id, null),
            sourceFormat: self::toString($row->source_format),
            importRunId: self::toInt($row->import_run_id),
            sourceRowIndex: self::toInt($row->source_row_index),
            sourceRef: self::toStringOrNull($row->source_ref),
        );
    }

    /**
     * @template T of int|null
     *
     * @param  T  $fallback
     * @return ($fallback is null ? int|null : int)
     */
    private static function toIntOrNull(mixed $value, ?int $fallback): ?int
    {
        if ($value === null) {
            return $fallback;
        }

        return is_numeric($value) ? (int) $value : $fallback;
    }
}
