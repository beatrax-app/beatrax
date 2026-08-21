<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// UNIQUE (inbox_id, provider_message_id) is the idempotency seam the fetcher
// leans on: a re-fetch of the same id is a no-op via insertOrIgnore.
// The fetcher only ever writes 'fetched'; 'parsed' / 'skipped' / 'unmatched'
// are reserved for the parser stage so it can transition rows in place.
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('inbox_messages', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('inbox_id')->constrained('inboxes')->cascadeOnDelete();
            // Gmail message ids are short hex; Graph ids run ~100 chars of
            // prefixed base64url. 128 covers both with margin.
            $table->string('provider_message_id', 128);
            $table->timestamp('internal_date');
            $table->string('sender_email', 320);
            $table->string('sender_name', 320)->nullable();
            // RFC 5322 caps a header line at 998 octets, and a Q-encoded
            // Subject can use all of it.
            $table->string('subject', 998)->nullable();
            $table->string('status', 16)->default('fetched');
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->unique(['inbox_id', 'provider_message_id']);
            $table->index(['user_id', 'status']);
            $table->index(['inbox_id', 'internal_date']);
        });

        $connection = $this->db()->connection($this->getConnection());
        $allowedStatuses = "'fetched','parsed','skipped','unmatched'";

        $connection->statement(sprintf(
            "CREATE TRIGGER inbox_messages_status_check_insert BEFORE INSERT ON inbox_messages FOR EACH ROW
             WHEN NEW.status NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid inbox_messages.status value'); END",
            $allowedStatuses,
        ));
        $connection->statement(sprintf(
            "CREATE TRIGGER inbox_messages_status_check_update BEFORE UPDATE OF status ON inbox_messages FOR EACH ROW
             WHEN NEW.status NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid inbox_messages.status value'); END",
            $allowedStatuses,
        ));
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('inbox_messages');
    }
};
