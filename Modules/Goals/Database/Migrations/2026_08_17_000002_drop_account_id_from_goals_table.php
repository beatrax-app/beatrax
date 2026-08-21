<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// `account_id` drove an unowned progress sum: every credit on the linked account
// counted toward the goal, so two goals sharing an account showed the same
// figure and any target below a month's income read as already reached. Progress
// now comes from a linked pot or from explicitly attributed transactions.
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('goals', static function (Blueprint $table): void {
            // FK first: SQLite's native DROP COLUMN refuses a column that is
            // still referenced by a constraint.
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
