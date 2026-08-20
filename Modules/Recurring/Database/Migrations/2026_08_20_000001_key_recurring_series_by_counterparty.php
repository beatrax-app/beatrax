<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Re-keys `recurring_series` on the counterparty rather than on the cluster
 * key and the latest currency.
 *
 * `rec_series_uniq` was `(user_id, direction, cluster_key, latest_currency)`,
 * and `SeriesRefresher` REWRITES both `cluster_key` and `latest_currency` on
 * the row every sweep — `cluster_key` encodes the cadence band, so a monthly
 * subscription that slips to quarterly moves its own uniqueness key. A
 * constraint whose columns the writer mutates identifies nothing: it neither
 * prevents a duplicate (the second row simply carries the new key) nor names
 * the row for anyone who needs to point at it.
 *
 * What actually makes it *that* series is the counterparty. The cadence band
 * and the latest currency are observations ABOUT the series, and the detector
 * already treats them that way: `ExpenseSeriesDetector` falls back to
 * `(user_id, direction, cluster_counterparty_key, latest_currency)` precisely
 * so a cadence flip lands on the existing row instead of inserting a second
 * one. This makes that fallback the primary reading.
 *
 * `latest_currency` is dropped from the key for the same reason: it is a
 * "latest_" metric the refresher overwrites, so a subscription that re-bills
 * in a different currency is the same subscription, not a new one.
 *
 * The column is nullable, and SQLite treats NULLs in a UNIQUE index as
 * distinct — which would let two NULL-keyed rows sit under one identity, and
 * would later hand both of them the same derived id. Two triggers close that:
 * the first fills the column from `detected_name` after an insert that left
 * it empty (the same rule this column's own backfill migration used), the
 * second refuses to empty it again. A BEFORE INSERT trigger cannot write to
 * NEW in SQLite, so the fill is AFTER INSERT and its UPDATE is what trips the
 * UNIQUE when two rows really do collapse onto one counterparty.
 *
 * @link ../../../../.docs/features/recurring/architecture.md
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $connection = $this->db()->connection($this->getConnection());

        $connection->table('recurring_series')
            ->where(function ($query): void {
                $query->whereNull('cluster_counterparty_key')->orWhere('cluster_counterparty_key', '');
            })
            ->update(['cluster_counterparty_key' => $connection->raw('detected_name')]);

        // Merging two series that are genuinely different is worse than the
        // bug being fixed here, so a collision stops the migration rather than
        // letting the UNIQUE below decide which row survives.
        $collisions = $connection->table('recurring_series')
            ->selectRaw('user_id, direction, cluster_counterparty_key, COUNT(*) as row_count')
            ->groupBy('user_id', 'direction', 'cluster_counterparty_key')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($collisions->isNotEmpty()) {
            $described = $collisions->map(static fn (object $row): string => sprintf(
                'user %s / %s / %s: %s rows',
                (string) ($row->user_id ?? 'null'),
                (string) ($row->direction ?? 'null'),
                (string) ($row->cluster_counterparty_key ?? 'null'),
                (string) ($row->row_count ?? '?'),
            ))->implode('; ');

            throw new RuntimeException(
                'recurring_series cannot be re-keyed on its counterparty: these groups already hold more than one row, '
                .'and collapsing them would merge series that are not the same series — '.$described,
            );
        }

        $this->schema()->table('recurring_series', static function (Blueprint $table): void {
            $table->dropUnique('rec_series_uniq');
            $table->unique(['user_id', 'direction', 'cluster_counterparty_key'], 'rec_series_uniq');
        });

        $connection->statement(
            "CREATE TRIGGER recurring_series_counterparty_key_fill AFTER INSERT ON recurring_series FOR EACH ROW
             WHEN NEW.cluster_counterparty_key IS NULL OR NEW.cluster_counterparty_key = ''
             BEGIN UPDATE recurring_series SET cluster_counterparty_key = NEW.detected_name WHERE id = NEW.id; END"
        );
        $connection->statement(
            "CREATE TRIGGER recurring_series_counterparty_key_check_update BEFORE UPDATE OF cluster_counterparty_key ON recurring_series FOR EACH ROW
             WHEN NEW.cluster_counterparty_key IS NULL OR NEW.cluster_counterparty_key = ''
             BEGIN SELECT RAISE(ABORT, 'recurring_series.cluster_counterparty_key identifies the series and may not be emptied'); END"
        );
    }

    public function down(): void
    {
        $connection = $this->db()->connection($this->getConnection());
        $connection->statement('DROP TRIGGER IF EXISTS recurring_series_counterparty_key_fill');
        $connection->statement('DROP TRIGGER IF EXISTS recurring_series_counterparty_key_check_update');

        $this->schema()->table('recurring_series', static function (Blueprint $table): void {
            $table->dropUnique('rec_series_uniq');
            $table->unique(['user_id', 'direction', 'cluster_key', 'latest_currency'], 'rec_series_uniq');
        });
    }
};
