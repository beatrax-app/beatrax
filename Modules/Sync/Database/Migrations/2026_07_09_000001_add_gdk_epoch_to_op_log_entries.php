<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Adds the `gdk_epoch` column to `op_log_entries` — Phase 14 (D-02/D-04)
 * foundation for at-rest + wire GDK-epoch field encryption.
 *
 * Column notes:
 *   - `gdk_epoch` NULLABLE INTEGER tags which GDK keyring epoch (Plan 02's
 *     `GdkKeyringService`) can decrypt this entry's `value` column, when the
 *     entry's (table, field) is in the `SensitiveFieldRegistry` sensitive
 *     set. NULL means the entry predates encryption being enabled for this
 *     user, or the field is not sensitive — its `value` stays plaintext
 *     JSON either way.
 *   - This column is STRUCTURAL/PLAINTEXT BY DESIGN — it carries only the
 *     epoch id (an integer), never any key material (T-14-01/T-14-03). Key
 *     material lives exclusively in the encrypted GDK keyring file on disk
 *     (mirrors the `device_registry` "no secret-key column" invariant,
 *     STRIDE T-12-02).
 *   - No index: this is a debug/quarantine filter column, not a hot query
 *     key — the existing `op_log_entries_replay_idx` /
 *     `op_log_entries_table_pk_idx` indexes are untouched.
 *   - Additive ALTER TABLE ADD COLUMN on SQLite is safe here: `op_log_entries`
 *     carries no triggers (unlike the `categorization_rules` table rebuild
 *     precedent noted in STATE [13.4-01], where dropping/re-adding indexes
 *     and FKs was required) — a plain nullable column add requires no
 *     trigger-preservation handling.
 */
return new class extends Migration
{
    private ?DatabaseManager $resolvedDb = null;

    public function up(): void
    {
        $this->schema()->table('op_log_entries', static function (Blueprint $table): void {
            $table->integer('gdk_epoch')->nullable()->after('value');
        });
    }

    public function down(): void
    {
        $this->schema()->table('op_log_entries', static function (Blueprint $table): void {
            $table->dropColumn('gdk_epoch');
        });
    }

    private function schema(): Builder
    {
        return $this->db()->connection($this->getConnection())->getSchemaBuilder();
    }

    private function db(): DatabaseManager
    {
        if ($this->resolvedDb === null) {
            /** @var DatabaseManager $db */
            $db = Container::getInstance()->make(DatabaseManager::class);
            $this->resolvedDb = $db;
        }

        return $this->resolvedDb;
    }
};
