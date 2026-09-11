<?php

declare(strict_types=1);

namespace Modules\Receipts\Public\Actions;

use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Enums\InboxMessageStatus;
use Modules\Core\Public\Support\Instant;
use Modules\Ingestion\Public\Enums\SourceFormat;
use Modules\Receipts\Internal\MatcherRegistry;
use Modules\Receipts\Public\Dto\CapturedReceipt;
use Modules\Receipts\Public\Dto\MatcherInputDto;
use Modules\Receipts\Public\Dto\MatchOutcomeDto;
use Modules\Receipts\Public\Enums\MatchOutcomeKind;
use Modules\Receipts\Public\Pipeline\EmlMimeReader;
use Modules\Receipts\Public\Pipeline\FileDropEmlBlobStore;
use Modules\Receipts\Public\Support\ReceiptCaptureLog;
use Throwable;

// The single Public entry point for processing one raw RFC 822 message,
// used by both the file-drop wizard path and the inbox handoff path.
// Never writes to transactions itself — chain hints ride through
// raw_payload and are re-emitted once the canonical row exists.
final readonly class RecordReceipt
{
    private const string FALLBACK_FILENAME = 'fallback.eml';

    public function __construct(
        private EmlMimeReader $reader,
        private MatcherRegistry $matchers,
        private FileDropEmlBlobStore $blobStore,
        private DatabaseManager $db,
        private Clock $clock,
    ) {}

    // $captures is how a caller learns WHAT was filed rather than only how the
    // matchers answered. Nothing ties a file_imports row to the run that wrote
    // it, so a screen reporting on a drop has no way to find these afterwards.
    public function __invoke(string $emlBytes, User $user, ?string $sourceFilename = null, ?ReceiptCaptureLog $captures = null): MatchOutcomeDto
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
            'source_kind' => self::sourceKindOf($sourceFilename),
            'source_filename' => $sourceFilename ?? self::FALLBACK_FILENAME,
            'provider_message_id' => $providerMessageId,
            'internal_date' => Instant::appLocal($internalDate),
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

        $alreadyRecorded = $inserted === 0 && $row->status !== InboxMessageStatus::Fetched->value;

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

        $captures?->record(new CapturedReceipt(
            senderEmail: $senderEmail,
            subject: $subject,
            internalDate: Instant::appLocal($internalDate),
            outcome: $outcome->kind,
            matcherKey: $outcome->matcherKey,
        ));

        // A second pass over bytes already recorded still hands the caller its
        // outcome. Returning nothing left the drop-folder scan's file
        // unconfirmable through the wizard afterwards, with the money in
        // neither place; FingerprintStage is what decides a duplicate.
        if ($alreadyRecorded) {
            return $outcome;
        }

        $update = [
            'updated_at' => $this->clock->now()->toDateTimeString(),
            'status' => $outcome->kind->toInboxStatus()->value,
        ];

        if ($outcome->kind === MatchOutcomeKind::Parsed) {
            $update['matcher_key'] = $outcome->matcherKey;
        }

        $connection->table('file_imports')
            ->where('id', $fileImportId)
            ->update($update);

        return $outcome;
    }

    // The transport the message arrived on, which is the archive's extension
    // for every message carved out of one. Hard-coded 'eml' left 'mbox' — one
    // of the two values the column's trigger allows — with no writer at all,
    // and every mbox row's audit kind contradicting its own filename.
    private static function sourceKindOf(?string $sourceFilename): string
    {
        $extension = strtolower(pathinfo($sourceFilename ?? self::FALLBACK_FILENAME, PATHINFO_EXTENSION));
        $format = SourceFormat::tryFrom($extension);

        return $format?->isReceiptFile() === true ? $format->value : SourceFormat::Eml->value;
    }

    // The RFC 822 Date: header carries the SENDER's offset. Everything
    // downstream — the Y/m blob folder, the DATETIME column, the notification's
    // occurrence day — is read back at the app's offset, so the instant is
    // moved into that frame here, once, rather than at each of those three.
    private function parseInternalDate(string $dateRaw): DateTimeImmutable
    {
        if ($dateRaw === '') {
            return $this->clock->now()->toDateTimeImmutable();
        }

        try {
            return Instant::inAppZone(new DateTimeImmutable($dateRaw));
        } catch (Throwable) {
            return $this->clock->now()->toDateTimeImmutable();
        }
    }
}
