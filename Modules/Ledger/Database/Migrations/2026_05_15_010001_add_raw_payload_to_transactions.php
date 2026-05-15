<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Adds a nullable JSON `raw_payload` column to `transactions` so source
 * adapters that need to preserve per-row source data (the ICS PDF
 * adapter's per-transaction extracted-text block; future adapters'
 * structured metadata) can persist it alongside the canonical row
 * without re-reading the source file at audit time.
 *
 * The column is nullable so adapter paths that do not persist a raw
 * payload keep working unchanged. The column has no unique-index or
 * query-index constraint; it is archive-only data that the ledger never
 * consults during the period-window dashboard queries.
 */
return new class extends Migration
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

    private function schema(): Builder
    {
        // Anonymous migrations are instantiated by Laravel's migrator with
        // no constructor arguments, so the schema builder is resolved
        // from the container at the migration boundary. This is the
        // standing Laravel-migration exception to the DI-only rule.
        /** @var DatabaseManager $db */
        $db = app(DatabaseManager::class);

        return $db->connection($this->getConnection())->getSchemaBuilder();
    }
};
