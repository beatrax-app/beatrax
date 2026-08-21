<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Modules\Core\Database\Support\ModuleMigration;

// A schema-level backstop for the one-active-pot-per-goal invariant PotWriter
// otherwise enforces alone; archived pots keep their goal_id freely. Blueprint
// has no portable partial-index API, hence the raw statement — SQLite and
// PostgreSQL both support filtered unique indexes, so a port survives it.
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->connection()->statement(
            "CREATE UNIQUE INDEX pots_active_goal_unique ON pots (goal_id) WHERE goal_id IS NOT NULL AND status = 'active'"
        );
    }

    public function down(): void
    {
        $this->connection()->statement('DROP INDEX IF EXISTS pots_active_goal_unique');
    }

    private function connection(): Connection
    {
        return $this->db()->connection($this->getConnection());
    }
};
