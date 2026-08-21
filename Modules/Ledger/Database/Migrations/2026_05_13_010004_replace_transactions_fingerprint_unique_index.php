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
        /** @var DatabaseManager $db */
        $db = app(DatabaseManager::class);

        return $db->connection($this->getConnection());
    }
};
