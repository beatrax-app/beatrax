<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * @link ../../../../.docs/features/ingestion/asn-description-delimiters.md
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        // Device-local, and deliberately absent from MergeRulesRegistry so it
        // never syncs: a backfill rewrites this device's rows with a raw write
        // that produces no op-log entry, so a peer still holds the old ones. A
        // replicated "done" would tell that peer to skip its own pass forever.
        $this->schema()->create('ledger_backfill_state', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->string('backfill', 64);
            $table->timestamp('completed_at');

            // A row IS the completion, so the pair has to be unique or a
            // re-entrant pass files a second one and the marker stops being
            // an answer to "has this user been done".
            $table->unique(['user_id', 'backfill']);
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('ledger_backfill_state');
    }
};
