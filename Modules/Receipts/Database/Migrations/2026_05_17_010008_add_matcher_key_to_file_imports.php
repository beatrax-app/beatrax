<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Adds a nullable `matcher_key` column to file_imports symmetrical
 * to the matcher_key column on inbox_messages. The matcher consumer
 * records which SenderMatcher claimed each file-drop row so audit
 * + re-parse paths can resolve back to the matcher that owned it.
 *
 * The column lives alongside the lifecycle `status` column: NULL
 * while the row is in `status='fetched'`; populated to e.g.
 * `'paypal-receipt'` once the matcher dispatch transitions the row
 * to `'parsed'` / `'skipped'`. The pair (user_id, matcher_key) is
 * indexed so per-user audit queries land on a composite index.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('file_imports', static function (Blueprint $table): void {
            $table->string('matcher_key', 64)->nullable()->after('status');
            $table->index(['user_id', 'matcher_key']);
        });
    }

    public function down(): void
    {
        $this->schema()->table('file_imports', static function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'matcher_key']);
            $table->dropColumn('matcher_key');
        });
    }
};
