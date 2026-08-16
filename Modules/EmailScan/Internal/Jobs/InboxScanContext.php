<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Jobs;

use DateTimeImmutable;
use Illuminate\Database\Connection;
use Modules\Core\Public\Contracts\Clock;
use Modules\EmailScan\Internal\InboxScanStateMachine;
use Modules\EmailScan\Internal\MimeHeaderParser;
use Modules\EmailScan\Public\Services\EmlBlobStore;
use Throwable;

/**
 * @link ../../../../.docs/features/email-scan/architecture.md
 */
final readonly class InboxScanContext
{
    // One instance is built per scan-job run and folds every fetched
    // message of that inbox through the same connection, blob store,
    // header parser and clock, so the per-run collaborators live here
    // as state and only the message id + raw bytes vary per call.
    public function __construct(
        public int $inboxId,
        public Clock $clock,
        public InboxScanStateMachine $sm,
        private Connection $connection,
        private EmlBlobStore $blobStore,
        private MimeHeaderParser $mime,
        private int $userId,
    ) {}

    // Read by the job to bind the guard before any API client runs; the
    // property itself stays private so nothing else re-scopes off it.
    public function userId(): int
    {
        return $this->userId;
    }

    public function alreadyIndexed(string $messageId): bool
    {
        // Skips a message already fetched+indexed before any provider
        // call: the history/delta walk, the "extend window" re-run and
        // the cursor-expiry fallback walk can each re-surface a message
        // a prior pass persisted, and refetching would burn quota.
        return $this->connection->table('inbox_messages')
            ->where('inbox_id', $this->inboxId)
            ->where('provider_message_id', $messageId)
            ->exists();
    }

    public function storeFetchedMessage(
        string $messageId,
        string $rawEml,
        ?DateTimeImmutable $providerInternalDate,
    ): void {
        // Provider-stamped internal date (Microsoft) vs. in-body Date:
        // header (Gmail); when the provider stamps nothing the fallback
        // goes through the project Clock so test-frozen time is honoured
        // rather than a raw new DateTimeImmutable('now').
        $fallbackDate = $providerInternalDate ?? $this->clock->now()->toDateTimeImmutable();
        $headers = $this->mime->parseHeadersWithFallbackDate($rawEml, $fallbackDate);

        $emlPath = $this->blobStore->pathFor(
            $this->userId,
            $this->inboxId,
            $headers->internalDate,
            $messageId,
        );
        $this->blobStore->put($emlPath, $rawEml);

        try {
            $this->connection->transaction(function () use ($messageId, $headers): void {
                $this->connection->statement('PRAGMA busy_timeout = 5000');
                $now = $this->clock->now()->toDateTimeString();
                $this->connection->table('inbox_messages')->insertOrIgnore([
                    'user_id' => $this->userId,
                    'inbox_id' => $this->inboxId,
                    'provider_message_id' => $messageId,
                    'internal_date' => $headers->internalDate->format('Y-m-d H:i:s'),
                    'sender_email' => $headers->senderEmail,
                    'sender_name' => $headers->senderName,
                    'subject' => $headers->subject,
                    'status' => 'fetched',
                    'fetched_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
        } catch (Throwable $e) {
            // Orphan-cleanup: the .eml is on disk but the DB insert never
            // landed — unlink so there is no untracked blob.
            $this->blobStore->delete($emlPath);
            throw $e;
        }
    }
}
