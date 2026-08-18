<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Drops `goals.account_id`.
 *
 * The column drove an unowned progress sum: every credit on the linked
 * account counted toward the goal, so two goals sharing an account showed
 * the same figure and any target below a month's income read as reached.
 * A goal's progress now comes from a linked pot's balance or from
 * explicitly attributed transactions (`goal_contributions`), both of which
 * belong to exactly one goal.
 *
 * The foreign key goes first — SQLite's native ALTER TABLE ... DROP COLUMN
 * refuses to drop a column still referenced by a constraint.
 *
 * `down()` restores the column shape, not its data: the account link was
 * never reconstructible from the goal alone.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('goals', static function (Blueprint $table): void {
            $table->dropForeign(['account_id']);
            $table->dropColumn('account_id');
        });
    }

    public function down(): void
    {
        $this->schema()->table('goals', static function (Blueprint $table): void {
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
        });
    }
};
