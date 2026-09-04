<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Jobs;

use DateTimeImmutable;
use Illuminate\Database\Connection;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Enums\InboxMessageStatus;
use Modules\Core\Public\Exceptions\BoundedReadException;
use Modules\Core\Public\Support\Instant;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\EmailScan\Internal\InboxScanStateMachine;
use Modules\EmailScan\Internal\MimeHeaderParser;
use Modules\EmailScan\Internal\ParsedMessageHeaders;
use Modules\EmailScan\Public\Services\EmlBlobStore;
use Psr\Log\LoggerInterface;
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
        public LoggerInterface $logger,
    ) {}

    // One message this device will not hold whole is one message skipped, not
    // a failed scan: a refusal let out of the walk leaves the cursor where it
    // was, and every later tick walks back into the same message.
    public function skipOversized(string $messageId, BoundedReadException $refusal): void
    {
        $this->logger->warning(
            'EmailScan: skipped a message larger than this device reads whole.',
            [
                'inbox_id' => $this->inboxId,
                'provider_message_id' => $messageId,
                'message' => $refusal->getMessage(),
            ],
        );
    }

    public function userId(): int
    {
        return $this->userId;
    }

    // The walk's own resume point, read back through the same row the state
    // machine writes it to.
    /**
     * @return array<string, mixed>|null
     */
    public function backfillProgress(): ?array
    {
        $raw = $this->connection->table('inboxes')->where('id', $this->inboxId)->value('backfill_progress');
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return null;
        }

        $out = [];
        foreach ($decoded as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
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

    // The cursor advances past a message the provider handed over and this
    // device could not read, so without this row the loss is an absence —
    // indistinguishable from an id the walk never saw. Written as skipped, it
    // is a message the reader can be shown and alreadyIndexed() can answer on.
    public function recordUndecodableMessage(string $messageId, Throwable $failure): void
    {
        $now = $this->clock->now();
        $stamp = $now->toDateTimeString();

        // No sender, no subject and no blob: the bytes those come from are
        // exactly what could not be decoded, and nothing from the payload may
        // be guessed at here.
        $this->connection->table('inbox_messages')->insertOrIgnore([
            'user_id' => $this->userId,
            'inbox_id' => $this->inboxId,
            'provider_message_id' => $messageId,
            'internal_date' => Instant::appLocal($now->toDateTimeImmutable()),
            'sender_email' => '',
            'sender_name' => null,
            'subject' => null,
            'status' => InboxMessageStatus::Skipped->value,
            'fetched_at' => $stamp,
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ]);

        $this->logger->warning('EmailScan: a fetched message could not be decoded and was skipped.', [
            'inbox_id' => $this->inboxId,
            'provider_message_id' => $messageId,
            ...SafeExceptionContext::describe($failure),
        ]);
    }

    // Split from storeParsedMessage so a caller that has to gate on the
    // sender (Gmail's history walk carries no from-address) can read the
    // headers once, without paying for a second parse of the same bytes.
    public function parseHeaders(string $rawEml, ?DateTimeImmutable $providerInternalDate): ParsedMessageHeaders
    {
        // Microsoft stamps an internal date, Gmail leaves only the in-body
        // Date: header; the Clock fallback keeps frozen test time honoured.
        $fallbackDate = $providerInternalDate ?? $this->clock->now()->toDateTimeImmutable();

        return $this->mime->parseHeadersWithFallbackDate($rawEml, $fallbackDate);
    }

    public function storeFetchedMessage(
        string $messageId,
        string $rawEml,
        ?DateTimeImmutable $providerInternalDate,
    ): void {
        $this->storeParsedMessage($messageId, $rawEml, $this->parseHeaders($rawEml, $providerInternalDate));
    }

    public function storeParsedMessage(string $messageId, string $rawEml, ParsedMessageHeaders $headers): void
    {
        // The header's offset is the sender's, and the provider's is UTC.
        // The blob's Y/m folder and the DATETIME column are both read back at
        // the app's offset, so the instant is moved into that frame once here.
        $internalDate = Instant::inAppZone($headers->internalDate);

        $emlPath = $this->blobStore->pathFor(
            $this->userId,
            $this->inboxId,
            $internalDate,
            $messageId,
        );
        $this->blobStore->put($emlPath, $rawEml);

        try {
            $this->connection->transaction(function () use ($messageId, $headers, $internalDate): void {
                $now = $this->clock->now()->toDateTimeString();
                $this->connection->table('inbox_messages')->insertOrIgnore([
                    'user_id' => $this->userId,
                    'inbox_id' => $this->inboxId,
                    'provider_message_id' => $messageId,
                    'internal_date' => Instant::appLocal($internalDate),
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
