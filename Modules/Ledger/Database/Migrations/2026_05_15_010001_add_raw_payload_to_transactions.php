<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a nullable JSON `raw_payload` column to `transactions` so source
 * adapters that need to preserve per-row source data (the ICS PDF
 * adapter's per-transaction extracted-text block; future adapters'
 * structured metadata) can persist it alongside the canonical row
 * without re-reading the source file at audit time.
 *
 * The column is nullable so the Phase 1/2 ASN adapter paths (which do
 * not persist a raw payload today) keep working unchanged. The column
 * has no unique-index or query-index constraint; it is archive-only
 * data that the ledger never consults during the period-window
 * dashboard queries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', static function (Blueprint $table): void {
            $table->json('raw_payload')->nullable()->after('source_ref');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', static function (Blueprint $table): void {
            $table->dropColumn('raw_payload');
        });
    }
};
