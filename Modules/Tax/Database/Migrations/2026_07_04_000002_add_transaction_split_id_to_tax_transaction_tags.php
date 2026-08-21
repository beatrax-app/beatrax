<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * @link ../../../../.docs/features/tax/tag-write-contract.md
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('tax_transaction_tags', static function (Blueprint $table): void {
            $table->foreignId('transaction_split_id')
                ->nullable()
                ->after('transaction_id')
                ->constrained('transaction_splits')
                ->cascadeOnDelete();
        });

        // A separate closure because SQLite cannot add the FK and rework the index
        // in one statement. Every NULL counts as distinct there, so the existing
        // whole-transaction rows (transaction_split_id IS NULL) satisfy the wider
        // constraint with no backfill.
        $this->schema()->table('tax_transaction_tags', static function (Blueprint $table): void {
            $table->dropUnique(['user_id', 'transaction_id']);
            $table->unique(['user_id', 'transaction_id', 'transaction_split_id']);
        });
    }

    public function down(): void
    {
        $this->schema()->table('tax_transaction_tags', static function (Blueprint $table): void {
            $table->dropUnique(['user_id', 'transaction_id', 'transaction_split_id']);
            $table->unique(['user_id', 'transaction_id']);
        });

        $this->schema()->table('tax_transaction_tags', static function (Blueprint $table): void {
            $table->dropForeign(['transaction_split_id']);
            $table->dropColumn('transaction_split_id');
        });
    }
};
