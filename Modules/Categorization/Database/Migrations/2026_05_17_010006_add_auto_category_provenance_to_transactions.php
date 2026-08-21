<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// Payload: {source: rule|memory, rule_id?, memory_id?, category_id}. It is
// not part of the fingerprint tuple, so adding it bumps no fingerprint
// version and needs no re-derive run.
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('transactions', static function (Blueprint $table): void {
            $table->json('auto_category_provenance')->nullable()->after('category_id');
        });
    }

    public function down(): void
    {
        $this->schema()->table('transactions', static function (Blueprint $table): void {
            $table->dropColumn('auto_category_provenance');
        });
    }
};
