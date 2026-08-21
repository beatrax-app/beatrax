<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// Deliberately not `constrained('counterparties')`: pruning an orphaned
// counterparty must not cascade its transaction history away. The garbage
// collector NULLs this column itself before deleting.
/**
 * @link ../../../../.docs/features/counterparties/garbage-collection.md#the-prune
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('transactions', static function (Blueprint $table): void {
            $table->unsignedBigInteger('counterparty_id')->nullable()->after('category_id');
            $table->index(['user_id', 'counterparty_id']);
        });
    }

    public function down(): void
    {
        $this->schema()->table('transactions', static function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'counterparty_id']);
            $table->dropColumn('counterparty_id');
        });
    }
};
