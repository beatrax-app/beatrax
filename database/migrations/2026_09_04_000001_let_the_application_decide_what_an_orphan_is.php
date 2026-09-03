<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Sync\Public\Exceptions\CascadeRemovalFailedException;

return new class extends Migration
{
    // Both pragmas below are no-ops inside a transaction, and SQLite cannot
    // roll this edit back in one either.
    public $withinTransaction = false;

    // A cascade removes the child and tells nothing: no tombstone is written,
    // the child's create op stays live in the log, and the peer resurrects the
    // row or quarantines it forever. Dropping the clause leaves NO ACTION,
    // which refuses the parent delete until the application clears the child.
    public function up(): void
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'sqlite') {
            return;
        }

        if ($this->stillCascading($connection) === 0) {
            return;
        }

        // Read back rather than assumed: leaving enforcement off would outlive
        // this migration on the connection that ran it, and a `set null` key
        // that never fires looks nothing like a migration fault.
        $enforced = $this->foreignKeysEnforced($connection);

        $connection->statement('PRAGMA foreign_keys=OFF');
        $connection->statement('PRAGMA writable_schema=ON');
        $connection->statement(
            "UPDATE sqlite_master SET sql = replace(sql, ' on delete cascade', '') "
            ."WHERE type='table' AND sql LIKE '%on delete cascade%'",
        );
        $connection->statement('PRAGMA writable_schema=RESET');
        $connection->statement('PRAGMA foreign_keys='.($enforced ? 'ON' : 'OFF'));

        $this->assertTheSchemaSurvived($connection, $enforced);
    }

    // Deliberately empty: which keys cascaded is precisely what this removed,
    // and a wrong guess would hand the database back rows it must not delete.
    public function down(): void {}

    private function stillCascading(Connection $connection): int
    {
        $row = $connection->selectOne(
            "select count(*) as n from sqlite_master where type='table' and sql like '%on delete cascade%'",
        );

        return is_object($row) && is_numeric($row->n ?? null) ? (int) $row->n : 0;
    }

    private function foreignKeysEnforced(Connection $connection): bool
    {
        $row = $connection->selectOne('PRAGMA foreign_keys');

        return is_object($row) && (int) ($row->foreign_keys ?? 1) === 1;
    }

    private function assertTheSchemaSurvived(Connection $connection, bool $enforced): void
    {
        $integrity = $connection->selectOne('PRAGMA integrity_check');
        $verdict = is_object($integrity) ? (string) ($integrity->integrity_check ?? '') : '';

        if ($verdict !== 'ok') {
            throw CascadeRemovalFailedException::schemaUnreadable($verdict);
        }

        $remaining = $this->stillCascading($connection);
        if ($remaining !== 0) {
            throw CascadeRemovalFailedException::stillCascading($remaining);
        }

        if ($enforced && ! $this->foreignKeysEnforced($connection)) {
            throw CascadeRemovalFailedException::foreignKeysUnenforced();
        }
    }
};
