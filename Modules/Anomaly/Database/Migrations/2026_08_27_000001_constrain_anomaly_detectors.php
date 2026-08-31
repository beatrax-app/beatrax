<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Modules\Anomaly\Internal\Enums\AnomalyDetector;
use Modules\Core\Database\Support\ModuleMigration;

return new class extends ModuleMigration
{
    // Re-runnable: every CREATE TRIGGER is preceded by its DROP, so the repair
    // half can be exercised against a database this has already migrated.
    public function up(): void
    {
        $connection = $this->db()->connection($this->getConnection());

        $this->realignBaselineSigns($connection);
        $this->dropUnknownDetectors($connection);
        $this->stripUnknownReasons($connection);

        $allowed = implode(',', array_map(
            static fn (string $value): string => "'".$value."'",
            AnomalyDetector::values(),
        ));

        foreach (['insert' => 'INSERT', 'update' => 'UPDATE OF detector'] as $suffix => $event) {
            $connection->statement('DROP TRIGGER IF EXISTS anomaly_suppression_rules_detector_check_'.$suffix);
            $connection->statement(sprintf(
                "CREATE TRIGGER anomaly_suppression_rules_detector_check_%s BEFORE %s ON anomaly_suppression_rules FOR EACH ROW
                 WHEN NEW.detector NOT IN (%s)
                 BEGIN SELECT RAISE(ABORT, 'Invalid anomaly_suppression_rules.detector value'); END",
                $suffix,
                $event,
                $allowed,
            ));
        }

        // An alert that names no reason cannot be explained to the reader, so
        // the empty list is rejected alongside the unknown one.
        foreach (['insert' => 'INSERT', 'update' => 'UPDATE OF reasons'] as $suffix => $event) {
            $connection->statement('DROP TRIGGER IF EXISTS anomaly_alerts_reasons_check_'.$suffix);
            $connection->statement(sprintf(
                "CREATE TRIGGER anomaly_alerts_reasons_check_%s BEFORE %s ON anomaly_alerts FOR EACH ROW
                 WHEN json_valid(NEW.reasons) = 0
                   OR json_type(NEW.reasons) <> 'array'
                   OR json_array_length(NEW.reasons) = 0
                   OR EXISTS (SELECT 1 FROM json_each(NEW.reasons) WHERE json_each.value NOT IN (%s))
                 BEGIN SELECT RAISE(ABORT, 'Invalid anomaly_alerts.reasons value'); END",
                $suffix,
                $event,
                $allowed,
            ));
        }
    }

    public function down(): void
    {
        $connection = $this->db()->connection($this->getConnection());

        foreach ([
            'anomaly_suppression_rules_detector_check_insert',
            'anomaly_suppression_rules_detector_check_update',
            'anomaly_alerts_reasons_check_insert',
            'anomaly_alerts_reasons_check_update',
        ] as $trigger) {
            $connection->statement('DROP TRIGGER IF EXISTS '.$trigger);
        }
    }

    // LargeVsTypicalDetector negated every baseline to the ledger's expense
    // sign, so an income alert reported "-3,000.00 -> 9,000.00". The pair
    // straddling zero is the whole test: same-direction samples never do.
    private function realignBaselineSigns(Connection $connection): void
    {
        $connection->statement(
            'UPDATE anomaly_alerts SET baseline_amount_minor = -baseline_amount_minor
             WHERE baseline_amount_minor IS NOT NULL
               AND latest_amount_minor IS NOT NULL
               AND baseline_amount_minor * latest_amount_minor < 0',
        );
    }

    // A rule naming a detector no build has mutes nothing — the evaluator's
    // whereIn never reaches it — and the settings screen rendered it as a raw
    // lang key. Deleting it is what the reader already sees.
    private function dropUnknownDetectors(Connection $connection): void
    {
        $connection->table('anomaly_suppression_rules')
            ->whereNotIn('detector', AnomalyDetector::values())
            ->delete();
    }

    private function stripUnknownReasons(Connection $connection): void
    {
        $known = AnomalyDetector::values();

        foreach ($connection->table('anomaly_alerts')->select('id', 'reasons')->orderBy('id')->cursor() as $row) {
            $decoded = is_string($row->reasons) ? json_decode($row->reasons, true) : null;
            if (! is_array($decoded)) {
                continue;
            }

            $kept = array_values(array_filter(
                $decoded,
                static fn (mixed $reason): bool => is_string($reason) && in_array($reason, $known, true),
            ));

            // An emptied list is left alone: the trigger below is scoped to
            // writes of `reasons`, and no path rewrites the column.
            if ($kept === [] || count($kept) === count($decoded)) {
                continue;
            }

            $connection->table('anomaly_alerts')->where('id', $row->id)->update(['reasons' => json_encode($kept)]);
        }
    }
};
