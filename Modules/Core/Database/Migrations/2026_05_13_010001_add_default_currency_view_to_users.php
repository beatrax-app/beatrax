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
        $this->schema()->table('users', static function (Blueprint $table): void {
            $table->string('default_currency_view', 16)->default('eur_only')->after('period_start_day');
        });
    }

    public function down(): void
    {
        $this->schema()->table('users', static function (Blueprint $table): void {
            $table->dropColumn('default_currency_view');
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
