<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

return new class extends ModuleMigration
{
    public function up(): void
    {
        // Which of the two values stands. NULL is the reader's outstanding
        // question and the only state the toast reads, so a disagreement the
        // stored policy already settled can be written down without asking
        // about it a second time.
        $this->schema()->table('pending_enrichment_conflicts', static function (Blueprint $table): void {
            $table->string('resolution', 32)->nullable()->after('incoming_source_format');
        });
    }

    public function down(): void
    {
        $this->schema()->table('pending_enrichment_conflicts', static function (Blueprint $table): void {
            $table->dropColumn('resolution');
        });
    }
};
