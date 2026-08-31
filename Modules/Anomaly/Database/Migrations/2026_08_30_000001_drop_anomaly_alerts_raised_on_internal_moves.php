<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Modules\Core\Database\Support\ModuleMigration;
use Modules\Core\Public\Support\RowChunk;
use Modules\Ledger\Public\Enums\TransactionType;

return new class extends ModuleMigration
{
    // Every alert opened against a transfer leg or an adjustment is one the
    // rule that replaced them would never raise, and the reader was told their
    // own savings transfer was an unusual charge. Re-runnable and a no-op on a
    // database holding none, so a from-scratch run and a post-dump run agree.
    public function up(): void
    {
        $connection = $this->db()->connection($this->getConnection());

        $alertIds = $this->alertsOnInternalMoves($connection);

        foreach (array_chunk($alertIds, RowChunk::DEFAULT_SIZE) as $chunk) {
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
    private function alertsOnInternalMoves(Connection $connection): array
    {
        // Read through the same predicate production reads, rather than a
        // second spelling of the type set: it keeps an unreadable `type` on
        // the surviving side, which is where a row of unknown shape belongs
        // when the pass that sees it deletes.
        $ids = [];
        $rows = $connection->table('anomaly_alerts')
            ->join('transactions', 'transactions.id', '=', 'anomaly_alerts.transaction_id')
            ->orderBy('anomaly_alerts.id')
            ->select(['anomaly_alerts.id as alert_id', 'transactions.type as type'])
            ->cursor();

        foreach ($rows as $row) {
            if (TransactionType::isExternalMovementOf($row->type)) {
                continue;
            }

            $ids[] = is_numeric($row->alert_id) ? (int) $row->alert_id : 0;
        }

        return array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
    }
};
