<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// `client_secret` and `tokens_blob` hold ciphertext: the owning model's
// `encrypted` cast keeps plaintext in the attribute layer only. The trigger
// pair stands in for a CHECK, which SQLite cannot add after the fact.
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('oauth_secrets', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('provider', 16);
            $table->string('client_id');
            $table->text('client_secret');
            $table->string('redirect_uri');
            $table->text('tokens_blob')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'provider']);
        });

        $connection = $this->db()->connection($this->getConnection());
        $allowedProviders = "'gmail','microsoft'";

        $connection->statement(sprintf(
            "CREATE TRIGGER oauth_secrets_provider_check_insert BEFORE INSERT ON oauth_secrets FOR EACH ROW
             WHEN NEW.provider NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid oauth_secrets.provider value'); END",
            $allowedProviders,
        ));
        $connection->statement(sprintf(
            "CREATE TRIGGER oauth_secrets_provider_check_update BEFORE UPDATE OF provider ON oauth_secrets FOR EACH ROW
             WHEN NEW.provider NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid oauth_secrets.provider value'); END",
            $allowedProviders,
        ));
    }

    public function down(): void
    {
        $connection = $this->db()->connection($this->getConnection());
        $connection->statement('DROP TRIGGER IF EXISTS oauth_secrets_provider_check_insert');
        $connection->statement('DROP TRIGGER IF EXISTS oauth_secrets_provider_check_update');

        $this->schema()->dropIfExists('oauth_secrets');
    }
};
