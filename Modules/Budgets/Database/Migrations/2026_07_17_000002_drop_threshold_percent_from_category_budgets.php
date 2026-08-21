<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// `category_budgets` is write-dead after the envelope cutover, so a threshold
// there could never fire; it moved to `envelope_settings` in 000003. Dropped
// forward rather than by deleting 000001, which would orphan the column on any
// dev DB that already ran it.
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('category_budgets', static function (Blueprint $table): void {
            $table->dropColumn('threshold_percent');
        });
    }

    public function down(): void
    {
        $this->schema()->table('category_budgets', static function (Blueprint $table): void {
            $table->unsignedTinyInteger('threshold_percent')->nullable();
        });
    }
};
