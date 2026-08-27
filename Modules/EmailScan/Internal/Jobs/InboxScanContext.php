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

final readonly class InboxScanContext
{
    public function __construct(
        public int $inboxId,
        public Clock $clock,
        public InboxScanStateMachine $sm,
        private Connection $connection,
        private EmlBlobStore $blobStore,
        private MimeHeaderParser $mime,
        private int $userId,
    ) {}

    public function userId(): int
    {
        return $this->userId;
    }

    public function alreadyIndexed(string $messageId): bool
    {
        // Checked before any provider call: the delta walk, an extended
        // window and the cursor-expiry fallback can each re-surface a message
        // a prior pass already landed, and refetching burns quota.
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
        // Microsoft stamps an internal date, Gmail leaves only the in-body
        // Date: header; the Clock fallback keeps frozen test time honoured.
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
