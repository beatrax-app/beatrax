<?php

declare(strict_types=1);

use Modules\Core\Database\Support\ModuleMigration;
use Modules\Recurring\Public\Enums\SeriesCadence;

/**
 * Constrains recurring_series.cadence to the vocabulary C2-R20 closes:
 * weekly, monthly, quarterly, yearly, irregular.
 *
 * The table was created with `state` guarded by a BEFORE INSERT / BEFORE
 * UPDATE trigger pair built from an explicit allowed list, and `cadence`
 * beside it as a bare string with a default. Two closed vocabularies on one
 * table, governed differently, for no reason anyone wrote down. This gives
 * the second one the same guard as the first.
 *
 * The allowed list is read from SeriesCadence rather than repeated, so the
 * enum stays the one place the vocabulary is written down.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $connection = $this->db()->connection($this->getConnection());

        $allowed = implode(',', array_map(
            static fn (string $value): string => "'".$value."'",
            SeriesCadence::values(),
        ));

        // Anything already outside the vocabulary becomes irregular, which
        // is the honest reading of a cadence nothing can interpret, and
        // leaves the row updatable once the trigger exists.
        $connection->table('recurring_series')
            ->whereNotIn('cadence', SeriesCadence::values())
            ->update(['cadence' => SeriesCadence::Irregular->value]);

        $connection->statement(sprintf(
            "CREATE TRIGGER recurring_series_cadence_check_insert BEFORE INSERT ON recurring_series FOR EACH ROW
             WHEN NEW.cadence NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid recurring_series.cadence value'); END",
            $allowed,
        ));
        $connection->statement(sprintf(
            "CREATE TRIGGER recurring_series_cadence_check_update BEFORE UPDATE OF cadence ON recurring_series FOR EACH ROW
             WHEN NEW.cadence NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid recurring_series.cadence value'); END",
            $allowed,
        ));
    }

    public function down(): void
    {
        $connection = $this->db()->connection($this->getConnection());
        $connection->statement('DROP TRIGGER IF EXISTS recurring_series_cadence_check_insert');
        $connection->statement('DROP TRIGGER IF EXISTS recurring_series_cadence_check_update');
    }
};
