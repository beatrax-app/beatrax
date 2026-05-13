<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $connection = $this->resolveConnection();
        $connection->statement('DROP INDEX IF EXISTS transactions_fingerprint_uq');
        $connection->statement(
            'CREATE UNIQUE INDEX transactions_fingerprint_uq ON transactions('
            .'user_id, account_id, posted_at, booked_at, amount_minor, currency, counterparty_normalized)'
        );
    }

    public function down(): void
    {
        $connection = $this->resolveConnection();
        $connection->statement('DROP INDEX IF EXISTS transactions_fingerprint_uq');
        $connection->statement(
            'CREATE UNIQUE INDEX transactions_fingerprint_uq ON transactions('
            .'user_id, account_id, posted_at, amount_minor, currency, counterparty_normalized, source_ref)'
        );
    }

    private function resolveConnection(): Connection
    {
        // Anonymous migrations are instantiated by Laravel's migrator with
        // no constructor arguments, so the connection is resolved from
        // the container at the migration boundary. This is the standing
        // Laravel-migration exception to the DI-only rule.
        /** @var DatabaseManager $db */
        $db = app(DatabaseManager::class);

        return $db->connection($this->getConnection());
    }
};
