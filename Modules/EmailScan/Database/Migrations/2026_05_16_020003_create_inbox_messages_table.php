<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Creates the inbox_messages table — the lightweight DB index that
 * sits over the raw .eml blobs persisted on disk.
 *
 * One row per fetched message. The pair (inbox_id, provider_message_id)
 * is UNIQUE so re-fetching the same message id is a no-op via
 * insertOrIgnore — the idempotency contract the background fetcher
 * relies on across retries and back-pagination. Sender + subject are
 * captured at fetch time so the downstream parser does not need to
 * re-open the .eml just to filter by sender; the body parsing happens
 * later via its own pipeline.
 *
 * The `status` column is the lifecycle handoff to the parser stage:
 * Phase 6's fetcher only ever writes 'fetched'. The remaining values
 * ('parsed', 'skipped', 'unmatched') are reserved so the parser-stage
 * code can transition rows in place without a follow-up schema change.
 * The enum is enforced via a paired BEFORE INSERT / BEFORE UPDATE
 * trigger.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('inbox_messages', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('inbox_id')->constrained('inboxes')->cascadeOnDelete();
            // Gmail message ids are short hex strings; Microsoft Graph
            // message ids are ~100 chars base64url with a long prefix.
            // 128 covers both with margin.
            $table->string('provider_message_id', 128);
            $table->timestamp('internal_date');
            $table->string('sender_email', 320);
            $table->string('sender_name', 320)->nullable();
            // RFC 5322 caps a single header line at 998 octets; the
            // Subject header may carry encoded-word run-on. Allow the
            // full line length so Q-encoded subjects round-trip safely.
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
