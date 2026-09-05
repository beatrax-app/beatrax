<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('system_alerts', static function (Blueprint $table): void {
            // Two desktop processes booted in the same second, each read "no
            // open alert of this kind", and each wrote one — so the reader was
            // told twice, in the same words, that OAuth redaction was offline.
            // A SELECT cannot see an INSERT that has not happened yet, so the
            // second write has to be refused rather than read about.
            //
            // NULL for every row that is MEANT to repeat: each failed recovery
            // attempt is its own row and so is each corrupt backup. SQLite
            // counts NULLs as distinct in a UNIQUE index, so leaving the key
            // unset is how a writer says "this one may happen again".
            $table->string('dedup_key', 128)->nullable();
            $table->unique('dedup_key', 'system_alerts_dedup_key_unique');
        });

        // The key names the row that is OPEN, so acknowledgement has to release
        // it or the next alert of the kind is refused for good. A schema-level
        // rail rather than a line in the action, because four writers stamp
        // this column: the acknowledge action, both reconnect paths in
        // ConnectInboxFromGrant, and the sync applier — which is a peer's
        // dismissal arriving as a raw UPDATE and reaches no PHP of ours at all.
        //
        // SQLite leaves recursive_triggers off by default, so the UPDATE below
        // does not re-enter this trigger. AFTER UPDATE OF names the column, so
        // a write that does not touch acknowledged_at never fires it.
        $this->db()->connection($this->getConnection())->statement(<<<'SQL'
            CREATE TRIGGER system_alerts_release_dedup_key AFTER UPDATE OF acknowledged_at ON system_alerts FOR EACH ROW
            WHEN NEW.acknowledged_at IS NOT NULL AND NEW.dedup_key IS NOT NULL
            BEGIN UPDATE system_alerts SET dedup_key = NULL WHERE id = NEW.id; END
        SQL);
    }

    public function down(): void
    {
        $this->db()->connection($this->getConnection())
            ->statement('DROP TRIGGER IF EXISTS system_alerts_release_dedup_key');

        $this->schema()->table('system_alerts', static function (Blueprint $table): void {
            $table->dropUnique('system_alerts_dedup_key_unique');
            $table->dropColumn('dedup_key');
        });
    }
};
