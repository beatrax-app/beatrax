<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// Nullable with no DB default: null means "use
// CarryoverQuery::DEFAULT_NOTIFY_THRESHOLD_PERCENT", and being nullable
// keeps the column out of envelope_settings' required-create sync set.
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('envelope_settings', static function (Blueprint $table): void {
            $table->unsignedTinyInteger('threshold_percent')->nullable();
        });
    }

    public function down(): void
    {
        $this->schema()->table('envelope_settings', static function (Blueprint $table): void {
            $table->dropColumn('threshold_percent');
        });
    }
};
