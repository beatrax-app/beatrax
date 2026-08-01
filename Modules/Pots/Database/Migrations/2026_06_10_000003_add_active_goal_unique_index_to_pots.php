<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * DB-level backstop for the D-11 one-pot-per-goal invariant (IN-08).
 *
 * The invariant is enforced in application code by
 * `PotWriter::assertGoalOwnedAndFree()` (save/update) and re-checked in
 * `PotWriter::restore()` (WR-04). This partial unique index guarantees it at
 * the schema level so no current or future write path can produce two ACTIVE
 * pots linked to the same goal — archived pots keep their goal_id freely.
 *
 * Partial (filtered) unique indexes are supported by SQLite 3.8+ (the v1
 * store) and PostgreSQL (the stated multi-user migration target), so this
 * survives the planned dump/load migration unchanged. Laravel's Blueprint has
 * no portable partial-index API, hence the raw statement.
 */
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
