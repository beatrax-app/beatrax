<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Creates the inboxes table — one row per connected email account.
 *
 * Each row pairs a user with an OAuth-authenticated mailbox (Gmail or
 * Microsoft 365) and carries the user-chosen backfill window plus a
 * JSON progress payload the background fetcher updates as it walks
 * history. Persistent OAuth credentials live exclusively in a
 * chmod-600 JSON repository on disk — never in any column of this
 * table — so deleting an inboxes row never leaks credentials and
 * cascading deletes simply unbind the in-memory id reference.
 *
 * The `provider` column is an enum-shaped string ('gmail' or
 * 'microsoft') enforced by a paired BEFORE INSERT / BEFORE UPDATE
 * trigger; SQLite cannot ALTER TABLE ADD CHECK after the fact, so the
 * trigger pair is the project-wide pattern for typed string columns.
 */
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
