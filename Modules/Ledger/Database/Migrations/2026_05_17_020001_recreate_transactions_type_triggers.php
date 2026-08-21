<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Adding a column rebuilds the table in SQLite and leaves the triggers
    // behind, so every later ALTER on transactions dropped these and bad type
    // values landed unrejected. This migration is timestamped after all of
    // them: a future ALTER must sort before it, or re-install them itself.
    public function up(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS transactions_type_check_insert');
        DB::statement('DROP TRIGGER IF EXISTS transactions_type_check_update');

        $allowedTypes = "'expense','income','transfer_out','transfer_in','fee','refund','adjustment'";
        DB::statement(sprintf(
            "CREATE TRIGGER transactions_type_check_insert BEFORE INSERT ON transactions FOR EACH ROW
             WHEN NEW.type NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid transactions.type value'); END",
            $allowedTypes,
        ));
        DB::statement(sprintf(
            "CREATE TRIGGER transactions_type_check_update BEFORE UPDATE OF type ON transactions FOR EACH ROW
             WHEN NEW.type NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid transactions.type value'); END",
            $allowedTypes,
        ));
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS transactions_type_check_insert');
        DB::statement('DROP TRIGGER IF EXISTS transactions_type_check_update');
    }
};
