<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md#a-digest-that-was-asked-to-answer-two-questions
 */
return new class extends ModuleMigration
{
    // Device-local, like every other column of this table. The digest beside
    // it says WHICH columns the last sweep covered; this says WHEN it ran,
    // which is the only question a writer that went around the codec — and so
    // announced nothing — can still be caught by.
    public function up(): void
    {
        $this->schema()->table('sync_encryption_state', static function (Blueprint $table): void {
            $table->timestamp('resealed_columns_at')->nullable();
        });
    }

    public function down(): void
    {
        $this->schema()->table('sync_encryption_state', static function (Blueprint $table): void {
            $table->dropColumn('resealed_columns_at');
        });
    }
};
