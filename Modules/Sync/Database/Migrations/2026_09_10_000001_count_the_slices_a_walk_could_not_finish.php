<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * @link ../../../../.docs/features/sync/pre-sync-history-capture.md#a-failed-slice-leaves-the-walk-owed
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('sync_backfill_state', static function (Blueprint $table): void {
            // Consecutive slices that ended with nothing captured. A failure
            // used to stamp completed_at, which is what made a lock lasting
            // three seconds a hole lasting forever; the count is what lets a
            // walk stay owed without being retried every two seconds for good.
            $table->unsignedInteger('failed_slices')->default(0);
        });
    }

    public function down(): void
    {
        $this->schema()->table('sync_backfill_state', static function (Blueprint $table): void {
            $table->dropColumn('failed_slices');
        });
    }
};
