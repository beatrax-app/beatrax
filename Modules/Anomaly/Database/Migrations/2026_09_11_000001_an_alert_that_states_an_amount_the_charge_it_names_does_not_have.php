<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Modules\Core\Database\Support\ModuleMigration;
use Modules\Core\Public\Support\RowChunk;

return new class extends ModuleMigration
{
    // `latest_amount_minor` is the charge's own `settled_amount_minor`, read
    // off the same row on both write paths — so a row where the two disagree
    // describes a charge other than the one it names. That is what a derived id
    // folded from a per-device autoincrement produced: a peer's alert for ITS
    // transaction 18 arrived carrying that number, and transaction 18 here is
    // another charge entirely. Seven of thirty-three alerts on the install this
    // was measured against said a EUR 85.00 debit was a duplicate of EUR 20.50.
    //
    // Deleted rather than corrected: nothing in the row says what the detectors
    // would have said about the charge it landed on, and a stated amount that
    // contradicts the charge is worse than no alert at all. SafetyNetAnomalySweepJob
    // re-evaluates a recent charge that has no alert, which is what puts a true
    // one back.
    //
    // Device-agnostic and idempotent by construction: the predicate is a row
    // against the row it points at, so each device judges its own copy and
    // reaches the same verdict about it, and a second run finds nothing. No
    // tombstone is emitted on purpose — the peer's copy of this alert is
    // correct THERE, and a delete that travelled would take a good row with it.
    public function up(): void
    {
        $connection = $this->db()->connection($this->getConnection());

        foreach (array_chunk($this->alertsContradictingTheirCharge($connection), RowChunk::DEFAULT_SIZE) as $chunk) {
            // The FKs already say cascade for the history and null for the
            // mute's provenance, but SQLite only honours them with the
            // foreign_keys pragma on. Stated here so the outcome is the same
            // either way, and in the order the constraints would apply it.
            $connection->table('anomaly_alert_transitions')->whereIn('anomaly_alert_id', $chunk)->delete();
            $connection->table('anomaly_suppression_rules')
                ->whereIn('source_anomaly_alert_id', $chunk)
                ->update(['source_anomaly_alert_id' => null]);
            $connection->table('anomaly_alerts')->whereIn('id', $chunk)->delete();
        }
    }

    public function down(): void
    {
        // Deliberately empty: an alert is detection output, not reader input,
        // and re-running the detectors is what restores one that is still due.
    }

    /**
     * @return list<int>
     */
    private function alertsContradictingTheirCharge(Connection $connection): array
    {
        $ids = [];

        $rows = $connection->table('anomaly_alerts')
            ->join('transactions', 'transactions.id', '=', 'anomaly_alerts.transaction_id')
            // A NULL latest amount is a row from a build that stamped none, not
            // a row describing another charge, and is not this pass's to judge.
            ->whereNotNull('anomaly_alerts.latest_amount_minor')
            ->whereColumn('anomaly_alerts.latest_amount_minor', '<>', 'transactions.settled_amount_minor')
            ->orderBy('anomaly_alerts.id')
            ->select(['anomaly_alerts.id as alert_id'])
            ->cursor();

        foreach ($rows as $row) {
            $ids[] = is_numeric($row->alert_id) ? (int) $row->alert_id : 0;
        }

        return array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
    }
};
