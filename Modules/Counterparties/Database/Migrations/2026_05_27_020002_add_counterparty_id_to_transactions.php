<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// Deliberately not `constrained('counterparties')`: a counterparty leaving
// must not cascade its transaction history away. Nothing deletes one on a
// timer any more, and this column is why that would have been survivable.
/**
 * @link ../../../../.docs/features/counterparties/retention.md
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
