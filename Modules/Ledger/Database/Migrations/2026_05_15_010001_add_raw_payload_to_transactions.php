<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// Nullable, because most adapters persist no raw payload; unindexed, because
// it is archive-only data no dashboard query ever consults.
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('transactions', static function (Blueprint $table): void {
            $table->json('raw_payload')->nullable()->after('source_ref');
        });
    }

    public function down(): void
    {
        $this->schema()->table('transactions', static function (Blueprint $table): void {
            $table->dropColumn('raw_payload');
        });
    }
};
