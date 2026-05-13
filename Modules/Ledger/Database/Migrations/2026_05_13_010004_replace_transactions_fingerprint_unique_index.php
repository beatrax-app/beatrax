<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS transactions_fingerprint_uq');
        DB::statement(
            'CREATE UNIQUE INDEX transactions_fingerprint_uq ON transactions('
            .'user_id, account_id, posted_at, booked_at, amount_minor, currency, counterparty_normalized)'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS transactions_fingerprint_uq');
        DB::statement(
            'CREATE UNIQUE INDEX transactions_fingerprint_uq ON transactions('
            .'user_id, account_id, posted_at, amount_minor, currency, counterparty_normalized, source_ref)'
        );
    }
};
