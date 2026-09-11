<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// The table has always meant one row per (map row, field), and only PHP said
// so. Two devices that each imported before pairing both put their own row on
// the wire; the arriving create had no index to refuse it, so it landed beside
// the local one. The three-way merge then read whichever row the query happened
// to return, and recordBaseline() advanced an arbitrary one — so a field the
// reader had edited compared equal to a baseline that was not the last
// import's, and the next import took the edit away in silence.

// The newest survives: the baseline answers "what the export said at the last
// import", so the row carrying the latest imported_at is the one that answer
// belongs to. The id breaks a tie, and both devices see the same rows once
// they have synced, so both compute the same survivor.
return new class extends ModuleMigration
{
    private const string INDEX = 'migration_import_baseline_map_field_unique';

    public function up(): void
    {
        if (! $this->schema()->hasTable('migration_import_baseline')) {
            return;
        }

        $this->keepTheNewestOfEachField($this->db()->connection($this->getConnection()));

        if ($this->indexIsAlreadyHere()) {
            return;
        }

        $this->schema()->table('migration_import_baseline', static function (Blueprint $table): void {
            $table->unique(['migration_source_map_id', 'field_name'], self::INDEX);
        });
    }

    public function down(): void
    {
        if (! $this->schema()->hasTable('migration_import_baseline') || ! $this->indexIsAlreadyHere()) {
            return;
        }

        $this->schema()->table('migration_import_baseline', static function (Blueprint $table): void {
            $table->dropUnique(self::INDEX);
        });
    }

    // A raw delete announces nothing, so the peer still holds its own row and
    // its own create op for it. That create is what the index now refuses:
    // the row comes back as an alias onto the survivor instead of as the
    // duplicate it was, which is why the delete sticks without a tombstone.
    private function keepTheNewestOfEachField(Connection $connection): void
    {
        $duplicated = $connection->table('migration_import_baseline')
            ->select('migration_source_map_id', 'field_name')
            ->groupBy('migration_source_map_id', 'field_name')
            ->havingRaw('count(*) > 1')
            ->get();

        foreach ($duplicated as $group) {
            $survivor = $connection->table('migration_import_baseline')
                ->where('migration_source_map_id', $group->migration_source_map_id)
                ->where('field_name', $group->field_name)
                ->orderByDesc('imported_at')
                ->orderByDesc('id')
                ->value('id');

            $connection->table('migration_import_baseline')
                ->where('migration_source_map_id', $group->migration_source_map_id)
                ->where('field_name', $group->field_name)
                ->where('id', '!=', $survivor)
                ->delete();
        }
    }

    // Re-runnable: the dedup above is worth running on its own, so up() is
    // allowed to reach a database that already carries the index.
    private function indexIsAlreadyHere(): bool
    {
        foreach ($this->schema()->getIndexes('migration_import_baseline') as $index) {
            if (($index['name'] ?? null) === self::INDEX) {
                return true;
            }
        }

        return false;
    }
};
