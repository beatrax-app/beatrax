<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// How ApplyEnrichments resolves a receipt disagreeing with an already
// imported CSV row. The default `unset` means "ask on the next conflict";
// under `prefer_first_write` the receipt's losing value is archived into
// pending_enrichment_conflicts rather than dropped.
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('users', static function (Blueprint $table): void {
            $table->string('receipt_conflict_resolution', 32)
                ->default('unset')
                ->after('default_currency_view');
        });

        $connection = $this->db()->connection($this->getConnection());
        $allowed = "'unset','prefer_receipt','prefer_first_write'";

        $connection->statement(sprintf(
            "CREATE TRIGGER users_receipt_conflict_resolution_check_insert BEFORE INSERT ON users FOR EACH ROW
             WHEN NEW.receipt_conflict_resolution NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid users.receipt_conflict_resolution value'); END",
            $allowed,
        ));
        $connection->statement(sprintf(
            "CREATE TRIGGER users_receipt_conflict_resolution_check_update BEFORE UPDATE OF receipt_conflict_resolution ON users FOR EACH ROW
             WHEN NEW.receipt_conflict_resolution NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid users.receipt_conflict_resolution value'); END",
            $allowed,
        ));
    }

    public function down(): void
    {
        $connection = $this->db()->connection($this->getConnection());
        $connection->statement('DROP TRIGGER IF EXISTS users_receipt_conflict_resolution_check_update');
        $connection->statement('DROP TRIGGER IF EXISTS users_receipt_conflict_resolution_check_insert');

        $this->schema()->table('users', static function (Blueprint $table): void {
            $table->dropColumn('receipt_conflict_resolution');
        });
    }
};
