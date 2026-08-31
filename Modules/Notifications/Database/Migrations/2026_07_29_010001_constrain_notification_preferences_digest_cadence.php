<?php

declare(strict_types=1);

use Modules\Core\Database\Support\ModuleMigration;
use Modules\Core\Public\Enums\DigestCadence;

// digest_cadence was created as a bare string, so only application code
// stopped a hand-written UPDATE from storing a value no enum case can
// represent.
return new class extends ModuleMigration
{
    public function up(): void
    {
        $connection = $this->db()->connection($this->getConnection());

        // Read from the enum so the vocabulary lives in exactly one place.
        $allowed = implode(',', array_map(
            static fn (string $value): string => "'".$value."'",
            DigestCadence::values(),
        ));

        // Any row already outside the vocabulary is moved to the default
        // first; the trigger would otherwise make it unupdatable.
        $connection->table('notification_preferences')
            ->whereNotIn('digest_cadence', DigestCadence::values())
            ->update(['digest_cadence' => DigestCadence::Weekly->value]);

        $connection->statement(sprintf(
            "CREATE TRIGGER notification_preferences_digest_cadence_check_insert BEFORE INSERT ON notification_preferences FOR EACH ROW
             WHEN NEW.digest_cadence NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid notification_preferences.digest_cadence value'); END",
            $allowed,
        ));
        $connection->statement(sprintf(
            "CREATE TRIGGER notification_preferences_digest_cadence_check_update BEFORE UPDATE OF digest_cadence ON notification_preferences FOR EACH ROW
             WHEN NEW.digest_cadence NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid notification_preferences.digest_cadence value'); END",
            $allowed,
        ));
    }

    public function down(): void
    {
        $connection = $this->db()->connection($this->getConnection());
        $connection->statement('DROP TRIGGER IF EXISTS notification_preferences_digest_cadence_check_insert');
        $connection->statement('DROP TRIGGER IF EXISTS notification_preferences_digest_cadence_check_update');
    }
};
