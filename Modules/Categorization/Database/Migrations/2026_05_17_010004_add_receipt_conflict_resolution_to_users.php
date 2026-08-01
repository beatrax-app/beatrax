<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Adds the `receipt_conflict_resolution` column to users — the
 * per-user policy that drives how ApplyEnrichments resolves field-
 * value conflicts between a previously-imported CSV row and a
 * newly-parsed receipt.
 *
 * Enum values:
 *
 *   - `unset` (default) — surface a one-time toast on the next
 *     conflict; capture the user's choice into this column.
 *   - `prefer_receipt` — receipts always overwrite the existing
 *     value when both are non-null and disagree.
 *   - `prefer_first_write` — the original (CSV) value wins; the
 *     receipt's value is archived into pending_enrichment_conflicts
 *     for later audit.
 *
 * The enum is enforced via paired BEFORE INSERT / BEFORE UPDATE
 * triggers as defence-in-depth on top of the PHP-side validation
 * the action layer performs.
 */
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
