<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * @link ../../../../.docs/conventions/a-check-another-writer-can-invalidate.md#a-claim-is-not-a-completion
 */
return new class extends ModuleMigration
{
    // Device-local, like the `users.anomaly_backfilled_at` it splits in two: a
    // walk is over this device's own transactions, and a peer that took this
    // row would skip a walk it never ran.
    //
    // The claim and the completion were one nullable timestamp, stamped before
    // the walk so a second dispatch could not start a second full walk. It
    // bought that and gave away the other half: a walk killed at the 300s
    // worker timeout left the stamp behind, every later attempt returned at
    // the "already backfilled" guard, and the rest of that history was never
    // evaluated. The shape is `sync_backfill_state`'s, which separates the two.
    public function up(): void
    {
        $this->schema()->create('anomaly_backfill_state', static function (Blueprint $table): void {
            $table->id();
            // Plain column, no FK: the walk runs on a queue worker that carries
            // no authenticated session for the BelongsToUser scope to bind.
            $table->unsignedInteger('user_id')->unique();

            // Where the walk got to: the highest transaction id already
            // evaluated. Null means it has evaluated nothing yet.
            $table->unsignedBigInteger('cursor_transaction_id')->nullable();

            // The claim, and only the claim. It is a lease rather than a stamp:
            // the walk refreshes it each chunk, so one that stopped refreshing
            // it is a dead walk and the next attempt may take it over.
            $table->string('claimed_at');

            // Which runner holds the lease. A retry of the same queued job
            // carries the uuid its killed attempt claimed under, so it resumes
            // without waiting the lease out; anybody else waits.
            $table->string('claimed_by');

            $table->string('started_at');

            // The completion, written only where the walk ran out of history.
            // `users.anomaly_backfilled_at` is stamped in the same transaction,
            // and that column is what the settings screen reads.
            $table->string('completed_at')->nullable();
            $table->string('updated_at');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('anomaly_backfill_state');
    }
};
