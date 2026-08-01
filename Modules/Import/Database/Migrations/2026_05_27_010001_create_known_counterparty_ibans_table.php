<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Creates the known_counterparty_ibans table — per-user alias from a
 * real institution IBAN (the one that appears on the ASN side of a
 * cross-account hop) to the user's own account kind (PayPal wallet,
 * ICS credit card) that uses a synthetic IBAN literal.
 *
 * Schema:
 *
 *   - id, user_id (FK with cascade delete) — per-user scope.
 *   - real_iban (34) — the institution IBAN literal that appears on
 *     the bank side, e.g. `LU89751000135104200E` for PayPal Luxembourg
 *     or `NL08ABNA0526650664` for ICS at ABN AMRO.
 *   - target_account_kind (16) — the kind of the user's own account
 *     the alias resolves to (`bank`, `ics_card`, `paypal`). Matches
 *     the `accounts.kind` value set.
 *   - notes (text, nullable) — free-form provenance memo (e.g. the
 *     legal entity name attached to the institution IBAN).
 *
 * UNIQUE (user_id, real_iban) blocks duplicate alias rows for the
 * same user at the DB layer. Index (real_iban) supports cross-user
 * diagnostics ("which users have an alias for this IBAN?").
 *
 * The target_account_kind allow-list is enforced via paired BEFORE
 * INSERT / BEFORE UPDATE triggers; an app-layer typo fails loud at
 * the database boundary with RAISE(ABORT, ...) rather than landing
 * a silently-broken row.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('known_counterparty_ibans', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('real_iban', 34);
            $table->string('target_account_kind', 16);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'real_iban']);
            $table->index(['real_iban']);
        });

        $connection = $this->db()->connection($this->getConnection());

        $allowedKinds = "'bank','ics_card','paypal'";
        $connection->statement(sprintf(
            "CREATE TRIGGER known_counterparty_ibans_target_account_kind_check_insert BEFORE INSERT ON known_counterparty_ibans FOR EACH ROW
             WHEN NEW.target_account_kind NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid known_counterparty_ibans.target_account_kind value'); END",
            $allowedKinds,
        ));
        $connection->statement(sprintf(
            "CREATE TRIGGER known_counterparty_ibans_target_account_kind_check_update BEFORE UPDATE OF target_account_kind ON known_counterparty_ibans FOR EACH ROW
             WHEN NEW.target_account_kind NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid known_counterparty_ibans.target_account_kind value'); END",
            $allowedKinds,
        ));
    }

    public function down(): void
    {
        $connection = $this->db()->connection($this->getConnection());
        $connection->statement('DROP TRIGGER IF EXISTS known_counterparty_ibans_target_account_kind_check_update');
        $connection->statement('DROP TRIGGER IF EXISTS known_counterparty_ibans_target_account_kind_check_insert');

        $this->schema()->dropIfExists('known_counterparty_ibans');
    }
};
