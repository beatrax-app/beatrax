<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// The counters on this table say how many rows were skipped; nothing said
// which ones. The preview cache that knew is dropped at confirm, so the run
// keeps a capped list of what it skipped and why. Row indexes, parser
// diagnostics and nothing a counterparty is named in.
return new class extends ModuleMigration
{
    public function up(): void
    {
        $schema = $this->schema();

        if (! $schema->hasColumn('import_runs', 'row_issues')) {
            $schema->table('import_runs', static function (Blueprint $table): void {
                $table->json('row_issues')->nullable()->after('error_count');
            });
        }
    }

    public function down(): void
    {
        $schema = $this->schema();

        if ($schema->hasColumn('import_runs', 'row_issues')) {
            $schema->table('import_runs', static function (Blueprint $table): void {
                $table->dropColumn('row_issues');
            });
        }
    }
};
