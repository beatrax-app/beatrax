<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('counterparties', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 16);
            // The slug is the kebab-cased display name and nothing else: it
            // ends up in a URL, so a personal counterparty's IBAN must not
            // reach it.
            $table->string('slug', 128);
            $table->string('display_name');
            $table->string('iban', 64)->nullable();
            $table->string('merchant_name')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            // The resolver picks its `-2`/`-3` suffix by querying for a free
            // slug, so this UNIQUE is what makes two concurrent resolves of
            // the same name safe.
            $table->unique(['user_id', 'slug']);
            $table->index(['user_id', 'type']);
        });

        $connection = $this->db()->connection($this->getConnection());

        $allowedTypes = "'merchant','personal','bank','government','self_account','unknown'";
        $connection->statement(sprintf(
            "CREATE TRIGGER counterparties_type_check_insert BEFORE INSERT ON counterparties FOR EACH ROW
             WHEN NEW.type NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid counterparties.type value'); END",
            $allowedTypes,
        ));
        $connection->statement(sprintf(
            "CREATE TRIGGER counterparties_type_check_update BEFORE UPDATE OF type ON counterparties FOR EACH ROW
             WHEN NEW.type NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid counterparties.type value'); END",
            $allowedTypes,
        ));
    }

    public function down(): void
    {
        $connection = $this->db()->connection($this->getConnection());
        $connection->statement('DROP TRIGGER IF EXISTS counterparties_type_check_update');
        $connection->statement('DROP TRIGGER IF EXISTS counterparties_type_check_insert');

        $this->schema()->dropIfExists('counterparties');
    }
};
