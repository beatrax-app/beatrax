<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// No column of this table carries an OAuth credential — those live only in the
// chmod-600 JSON repository on disk.
// SQLite cannot ALTER TABLE ADD CHECK, so a BEFORE INSERT / BEFORE UPDATE
// trigger pair is this project's stand-in for an enum constraint.
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('inboxes', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('provider', 16);
            // RFC 5321 caps the addr-spec at 254 octets; round up to 320
            // (local-part 64 + '@' + domain 255) to leave room for IDNs.
            $table->string('email', 320);
            $table->unsignedTinyInteger('backfill_window_months')->default(3);
            // JSON payload shape: { fetched_count, total_estimated, last_message_date }.
            // Nullable until the first backfill run writes the initial snapshot.
            $table->json('backfill_progress')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'provider']);
            $table->index(['user_id', 'created_at']);
        });

        $connection = $this->db()->connection($this->getConnection());
        $allowedProviders = "'gmail','microsoft'";

        $connection->statement(sprintf(
            "CREATE TRIGGER inboxes_provider_check_insert BEFORE INSERT ON inboxes FOR EACH ROW
             WHEN NEW.provider NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid inboxes.provider value'); END",
            $allowedProviders,
        ));
        $connection->statement(sprintf(
            "CREATE TRIGGER inboxes_provider_check_update BEFORE UPDATE OF provider ON inboxes FOR EACH ROW
             WHEN NEW.provider NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid inboxes.provider value'); END",
            $allowedProviders,
        ));
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('inboxes');
    }
};
