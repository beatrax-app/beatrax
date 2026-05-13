<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return new class extends Migration
{
    public function up(): void
    {
        $this->schema()->table('transactions', static function (Blueprint $table): void {
            $table->json('enriched_from')->nullable()->after('source_ref');
        });
    }

    public function down(): void
    {
        $this->schema()->table('transactions', static function (Blueprint $table): void {
            $table->dropColumn('enriched_from');
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
