<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Adds the `result_json` TEXT column on `forecast_runs` — the carrier
 * for the projection pipeline's per-account points payload.
 *
 * ProjectionPipeline serializes its `result` array to JSON and writes
 * it to this column at the completion boundary; ForecastQuery reads
 * the column back when /forecast renders. The column is nullable so
 * a pending / running run carries no payload and a failed run carries
 * no half-written body either.
 *
 * Stored as TEXT (SQLite has no native JSON column type); applications
 * decode on read.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('forecast_runs', static function (Blueprint $table): void {
            $table->text('result_json')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        $this->schema()->table('forecast_runs', static function (Blueprint $table): void {
            $table->dropColumn('result_json');
        });
    }
};
