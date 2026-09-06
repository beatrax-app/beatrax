<?php

declare(strict_types=1);

use Modules\Core\Database\Support\ModuleMigration;

// The column's vocabulary was a comment and nothing else, and two of the five
// values it listed had no writer anywhere in the tree. wizard_progress.status
// is guarded this way for the same reason: a set of states nothing enforces is
// one the next writer widens by accident, and every reader inherits the widening.
return new class extends ModuleMigration
{
    public function up(): void
    {
        $connection = $this->db()->connection($this->getConnection());

        $connection->statement(<<<'SQL'
            CREATE TRIGGER sync_sessions_status_check_insert BEFORE INSERT ON sync_sessions FOR EACH ROW
            WHEN NEW.status NOT IN ('active','closed','failed')
            BEGIN SELECT RAISE(ABORT, 'Invalid sync_sessions.status value'); END
        SQL);

        $connection->statement(<<<'SQL'
            CREATE TRIGGER sync_sessions_status_check_update BEFORE UPDATE OF status ON sync_sessions FOR EACH ROW
            WHEN NEW.status NOT IN ('active','closed','failed')
            BEGIN SELECT RAISE(ABORT, 'Invalid sync_sessions.status value'); END
        SQL);
    }

    public function down(): void
    {
        $connection = $this->db()->connection($this->getConnection());

        $connection->statement('DROP TRIGGER IF EXISTS sync_sessions_status_check_insert');
        $connection->statement('DROP TRIGGER IF EXISTS sync_sessions_status_check_update');
    }
};
