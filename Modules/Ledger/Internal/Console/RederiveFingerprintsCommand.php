<?php

declare(strict_types=1);

namespace Modules\Ledger\Internal\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Ledger\Public\Services\FingerprintComposer;
use stdClass;

/**
 * Re-computes the SHA-256 fingerprint of every transactions row using the
 * current FingerprintComposer NORMALIZATION_VERSION. Walks rows whose
 * stored `normalization_version` is below the composer's version, computes
 * the new fingerprint in memory, and detects collisions before any write
 * touches the table. When a collision is found the command aborts and
 * leaves the existing rows on their previous normalization_version; when
 * the pre-check passes the updates run inside a single DB transaction so
 * the table never lands in a partial state.
 *
 * The runtime guard against accidental HTTP invocation is registered in
 * the LedgerServiceProvider via the `runningInConsole()` check; the
 * compile-time guard is the BoundaryArchTest rule that asserts this class
 * is never used from any Http or Routes namespace.
 */
final class RederiveFingerprintsCommand extends Command
{
    /** @var string */
    protected $signature = 'diederik:rederive-fingerprints
        {--confirm : Apply the update inside a single DB transaction.}
        {--dry-run : Compute the new fingerprints in memory and report without writing.}';

    /** @var string */
    protected $description = 'Re-compute the SHA-256 fingerprint of every transactions row using the current FingerprintComposer NORMALIZATION_VERSION. Aborts cleanly if the new tuple would collide on existing data.';

    public function __construct(
        private readonly FingerprintComposer $fingerprints,
        private readonly DatabaseManager $db,
    ) {
        parent::__construct();
    }

    public function handle(): int
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
        /** @var array<int, string> $updates Maps transactions.id to the new SHA-256 fingerprint */
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
            $count = count($collisions);
            $json = json_encode($collisions, JSON_PRETTY_PRINT);

            $this->error(sprintf('Fingerprint v%d migration ABORTED.', $targetVersion));
            $this->error(sprintf('%d collision(s) detected:', $count));
            $this->line(is_string($json) ? $json : '[json_encode failed]');
            $this->error('Existing rows left intact. Manual reconciliation required before re-running.');

            return self::FAILURE;
        }

        $pendingCount = count($updates);

        $isDryRun = $this->option('dry-run') === true;
        $isConfirmed = $this->option('confirm') === true;

        if ($pendingCount === 0) {
            $this->info(sprintf('0 rows would be re-derived (already on v%d).', $targetVersion));

            return self::SUCCESS;
        }

        if ($isDryRun || ! $isConfirmed) {
            $this->info(sprintf('Dry-run OK. %d rows would be re-derived to v%d.', $pendingCount, $targetVersion));

            return self::SUCCESS;
        }

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

        $this->info(sprintf('Re-derived %d rows to v%d.', $pendingCount, $targetVersion));

        return self::SUCCESS;
    }

    /**
     * Reconstructs a CanonicalTransaction from a raw stored row so the
     * composer can hash it under the current tuple shape. Date columns
     * stored as ISO strings round-trip through CarbonImmutable so the
     * composer's `->toDateString()` and `->toDateTimeString()` calls see
     * the same value they would for a freshly-built canonical.
     */
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
            fxRateUsed: self::toStringOrNull($row->fx_rate_used),
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

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
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

    private static function toString(mixed $value): string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }

    private static function toStringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        return is_scalar($value) ? (string) $value : null;
    }
}
