<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// Aliases a real institution IBAN, as it appears on the bank side of a
// cross-account hop, to the kind of the user's own account that carries a
// synthetic IBAN literal instead.
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
