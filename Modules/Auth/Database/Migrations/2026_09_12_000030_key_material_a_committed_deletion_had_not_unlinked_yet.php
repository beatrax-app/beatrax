<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * @link ../../../../.docs/features/auth/user-scoped-purge.md#the-two-file-tiers-and-why-neither-is-inside-the-transaction
 */
return new class extends ModuleMigration
{
    // The three unlinks that end an account used to run inside the deletion
    // transaction, so any rollback past them restored the rows over destroyed
    // keys: an account nobody can read, with no tombstone and nothing raised.
    // The unlink moved past the commit; this row is what commits in its place.
    public function up(): void
    {
        $this->schema()->create('account_key_purge_state', static function (Blueprint $table): void {
            $table->id();

            // Not `user_id`, and the name is load-bearing: UserScopedDataPurge
            // discovers the tables it sweeps by that column, so spelling it
            // that way would delete, inside the very transaction that writes
            // it, the row recording what the transaction still owes.
            $table->unsignedInteger('account_id')->unique();

            // The claim, written inside the deletion transaction so it commits
            // exactly when the rows go and rolls back exactly when they stay.
            $table->timestamp('claimed_at');

            // The completion, stamped only where all three paths are confirmed
            // gone. A row without it is key material this device still holds
            // for an account it no longer has, and the sweep retries it: a
            // refused unlink is usually a held handle that is later released.
            $table->timestamp('completed_at')->nullable();

            $table->timestamp('updated_at');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('account_key_purge_state');
    }
};
