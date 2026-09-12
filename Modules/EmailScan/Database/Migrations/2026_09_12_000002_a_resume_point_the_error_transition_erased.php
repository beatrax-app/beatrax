<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * @link ../../../../.docs/conventions/a-check-another-writer-can-invalidate.md#a-claim-is-not-a-completion
 */
return new class extends ModuleMigration
{
    // `inboxes.backfill_progress` carried two facts in one column: the count
    // the inboxes strip draws, and the page a retried walk picks up from.
    // Every transition out of flight nulls it, so that a backfill which died
    // stops advertising a number that will never move again — and the same
    // statement took the resume point with it. The error transition is the one
    // a retry rides, so every retry restarted at page one.
    //
    // The walk's half moves here, beside the two provider cursors this table
    // already holds, where the display clear cannot reach it. The job clears
    // it itself once the walk finishes, and again once its attempts are spent.
    public function up(): void
    {
        $this->schema()->table('inbox_scan_state', static function (Blueprint $table): void {
            $table->text('backfill_resume_point')->nullable()->after('last_delta_link');
        });
    }

    public function down(): void
    {
        $this->schema()->table('inbox_scan_state', static function (Blueprint $table): void {
            $table->dropColumn('backfill_resume_point');
        });
    }
};
