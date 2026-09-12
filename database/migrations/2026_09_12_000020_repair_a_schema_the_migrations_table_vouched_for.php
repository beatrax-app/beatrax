<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Core\Public\Support\SchemaShape;
use Modules\Sync\Public\Exceptions\CascadeRemovalFailedException;

return new class extends Migration
{
    // Both pragmas below are no-ops inside a transaction, and SQLite cannot
    // roll a sqlite_master edit back in one either.
    public $withinTransaction = false;

    // An installed database carrying both faults has been found while its
    // `migrations` rows recorded both repairs as run, so neither can ever fire
    // again there. This one asks the schema instead, and does nothing to a
    // database that is already the shape the migrations declare.
    public function up(): void
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'sqlite') {
            return;
        }

        $this->removeSurvivingCascades($connection);
        $this->restoreMissingEnumGuards($connection);
    }

    // Deliberately empty, for the reason the removal it repeats gave: which
    // keys cascaded is precisely what this took away, and a wrong guess would
    // hand the database back rows it must not delete.
    public function down(): void {}

    private function removeSurvivingCascades(Connection $connection): void
    {
        if (SchemaShape::cascadingTables($connection) === []) {
            return;
        }

        // Read back rather than assumed: leaving enforcement off would outlive
        // this migration on the connection that ran it, and a `set null` key
        // that never fires looks nothing like a migration fault.
        $enforced = $this->foreignKeysEnforced($connection);

        $connection->statement('PRAGMA foreign_keys=OFF');
        $connection->statement('PRAGMA writable_schema=ON');
        $connection->statement(SchemaShape::STRIP_CASCADES);
        $connection->statement('PRAGMA writable_schema=RESET');
        $connection->statement('PRAGMA foreign_keys='.($enforced ? 'ON' : 'OFF'));

        $this->assertTheSchemaSurvived($connection, $enforced);
    }

    // A rebuild keeps the columns and the indices and drops every trigger, so
    // the guards go missing without the rebuild failing. Re-creating one that
    // is already there is what the drop before it makes safe.
    private function restoreMissingEnumGuards(Connection $connection): void
    {
        $definitions = SchemaShape::enumGuardTriggers();

        foreach (SchemaShape::missingTriggers($connection) as $name) {
            $connection->statement('DROP TRIGGER IF EXISTS '.$name);
            $connection->statement($definitions[$name]);
        }
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

        $remaining = count(SchemaShape::cascadingTables($connection));
        if ($remaining !== 0) {
            throw CascadeRemovalFailedException::stillCascading($remaining);
        }

        if ($enforced && ! $this->foreignKeysEnforced($connection)) {
            throw CascadeRemovalFailedException::foreignKeysUnenforced();
        }
    }
};
