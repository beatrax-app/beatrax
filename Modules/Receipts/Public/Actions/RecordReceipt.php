<?php

declare(strict_types=1);

namespace Modules\Receipts\Public\Actions;

use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Enums\InboxMessageStatus;
use Modules\Receipts\Internal\MatcherRegistry;
use Modules\Receipts\Public\Dto\MatcherInputDto;
use Modules\Receipts\Public\Dto\MatchOutcomeDto;
use Modules\Receipts\Public\Enums\MatchOutcomeKind;
use Modules\Receipts\Public\Pipeline\EmlMimeReader;
use Modules\Receipts\Public\Pipeline\FileDropEmlBlobStore;
use Throwable;

// The single Public entry point for processing one raw RFC 822 message,
// used by both the file-drop wizard path and the inbox handoff path.
// Never writes to transactions itself — chain hints ride through
// raw_payload and are re-emitted once the canonical row exists.
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

        // Dedup and the blob path key on the content hash, never the RFC 822
        // Message-ID: that header is attacker-chosen, so keying on it would let
        // a crafted message pre-occupy a real receipt's slot and have it
        // dropped as a duplicate. Identical bytes still collapse to one row.
        $providerMessageId = hash('sha256', $emlBytes);

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
            'status' => InboxMessageStatus::Fetched->value,
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

        if ($inserted === 0 && $row->status !== InboxMessageStatus::Fetched->value) {
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

        if ($outcome->kind === MatchOutcomeKind::Parsed && $outcome->parsed !== null) {
            $update['status'] = InboxMessageStatus::Parsed->value;
            $rawKey = $outcome->parsed->rawPayload['matcher_key'] ?? null;
            $update['matcher_key'] = is_string($rawKey) && $rawKey !== '' ? $rawKey : null;
        } elseif ($outcome->kind === MatchOutcomeKind::Skipped) {
            $update['status'] = InboxMessageStatus::Skipped->value;
        } else {
            $update['status'] = InboxMessageStatus::Unmatched->value;
        }

        $connection->table('file_imports')
            ->where('id', $fileImportId)
            ->update($update);

        return $outcome;
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
