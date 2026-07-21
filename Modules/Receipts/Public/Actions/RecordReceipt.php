<?php

declare(strict_types=1);

namespace Modules\Receipts\Public\Actions;

use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Receipts\Internal\MatcherRegistry;
use Modules\Receipts\Public\Dto\MatcherInputDto;
use Modules\Receipts\Public\Dto\MatchOutcomeDto;
use Modules\Receipts\Public\Pipeline\EmlMimeReader;
use Modules\Receipts\Public\Pipeline\FileDropEmlBlobStore;
use Throwable;

// The single Public entry point for processing one raw RFC 822 message,
// used by both the file-drop wizard path and the inbox handoff path.
// Never writes to transactions itself — chain hints ride through
// raw_payload and are re-emitted once the canonical row exists.
/**
 * @link ../../../../.docs/features/receipts/architecture.md
 */
final class RecordReceipt
{
    public function __construct(
        private readonly EmlMimeReader $reader,
        private readonly MatcherRegistry $matchers,
        private readonly FileDropEmlBlobStore $blobStore,
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
    ) {}

    public function __invoke(string $emlBytes, User $user, ?string $sourceFilename = null): MatchOutcomeDto
    {
        $parsed = $this->reader->read($emlBytes);
        $messageId = $parsed->headers['message-id'] ?? '';
        $providerMessageId = $messageId !== ''
            ? $this->sanitiseMessageId($messageId)
            : hash('sha256', $emlBytes);

        $senderEmail = $parsed->headers['from'] ?? '';
        $subject = $parsed->headers['subject'] ?? null;
        $dateRaw = $parsed->headers['date'] ?? '';
        $internalDate = $this->parseInternalDate($dateRaw);

        $blobPath = $this->blobStore->pathFor(
            userId: $user->id,
            internalDate: $internalDate,
            messageIdHash: $providerMessageId,
        );

        if (! $this->blobStore->exists($blobPath)) {
            $this->blobStore->put($blobPath, $emlBytes);
        }

        $connection = $this->db->connection();
        $now = $this->clock->now()->toDateTimeString();

        $inserted = $connection->table('file_imports')->insertOrIgnore([
            'user_id' => $user->id,
            'source_kind' => 'eml',
            'source_filename' => $sourceFilename ?? 'fallback.eml',
            'provider_message_id' => $providerMessageId,
            'internal_date' => $internalDate->format('Y-m-d H:i:s'),
            'sender_email' => $senderEmail,
            'sender_name' => null,
            'subject' => $subject,
            'eml_path' => $blobPath,
            'status' => 'fetched',
            'fetched_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $row = $connection->table('file_imports')
            ->where('user_id', $user->id)
            ->where('provider_message_id', $providerMessageId)
            ->first();
        if ($row === null) {
            return MatchOutcomeDto::unmatched('persist_failed');
        }
        $rawId = $row->id;
        $fileImportId = is_numeric($rawId) ? (int) $rawId : 0;

        if ($inserted === 0 && $row->status !== 'fetched') {
            // Already processed by a prior drop — never re-dispatch or
            // yield a duplicate canonical row to the caller.
            return MatchOutcomeDto::unmatched('duplicate_drop');
        }

        $input = new MatcherInputDto(
            id: $fileImportId,
            userId: $user->id,
            source: 'file-drop',
            providerMessageId: $providerMessageId,
            senderEmail: $senderEmail,
            senderName: null,
            subject: $subject,
            internalDate: $internalDate,
            emlPath: $blobPath,
        );

        $outcome = $this->matchers->dispatch($input, $emlBytes);
        $update = [
            'updated_at' => $this->clock->now()->toDateTimeString(),
        ];

        if ($outcome->kind === 'parsed' && $outcome->parsed !== null) {
            $update['status'] = 'parsed';
            $rawKey = $outcome->parsed->rawPayload['matcher_key'] ?? null;
            $update['matcher_key'] = is_string($rawKey) && $rawKey !== '' ? $rawKey : null;
        } elseif ($outcome->kind === 'skipped') {
            $update['status'] = 'skipped';
        } else {
            $update['status'] = 'unmatched';
        }

        $connection->table('file_imports')
            ->where('id', $fileImportId)
            ->update($update);

        return $outcome;
    }

    // Sanitises the Message-ID to fit the blob store's
    // [A-Za-z0-9._-]{1,200} allow-list — falls back to sha256(value)
    // when the sanitised result would otherwise be empty.
    private function sanitiseMessageId(string $raw): string
    {
        $trimmed = trim($raw, " \t\n\r\0\x0B<>");
        $sanitised = (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $trimmed);
        $sanitised = substr($sanitised, 0, 200);
        if ($sanitised === '' || $sanitised === '-') {
            return hash('sha256', $raw);
        }

        return $sanitised;
    }

    private function parseInternalDate(string $dateRaw): DateTimeImmutable
    {
        if ($dateRaw === '') {
            return $this->clock->now()->toDateTimeImmutable();
        }

        try {
            return new DateTimeImmutable($dateRaw);
        } catch (Throwable) {
            return $this->clock->now()->toDateTimeImmutable();
        }
    }
}
