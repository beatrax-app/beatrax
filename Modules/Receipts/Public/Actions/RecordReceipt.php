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

/**
 * Single Public entrypoint for processing one raw RFC 822 message
 * through the matcher pipeline.
 *
 * Used by two consumers — kept in lockstep so file-drop and inbox
 * paths cannot drift:
 *
 *  - `ParseStage` (the file-drop wizard path) invokes RecordReceipt
 *    once per dropped `.eml` and once per message inside a `.mbox`,
 *    then bridges any `parsed` outcome to a SourceTransactionDto via
 *    ReceiptSourceAdapter.
 *  - `ProcessFetchedInboxMessagesJob` (the inbox handoff path) walks
 *    `inbox_messages.status='fetched'` rows for a user and invokes
 *    RecordReceipt against the on-disk `.eml` bytes for each one.
 *
 * Per-row side effects:
 *
 *  1. Read MIME headers via EmlMimeReader to extract Message-ID
 *     (synthetic sha256 fallback when absent), From, Date.
 *  2. UPSERT a `file_imports` row keyed by the
 *     (user_id, provider_message_id) UNIQUE — re-drops short-circuit
 *     to the existing row idempotently.
 *  3. Atomic .eml-then-DB ordering via FileDropEmlBlobStore.put().
 *  4. Dispatch the message through MatcherRegistry against the bytes.
 *  5. Transition the file_imports row to 'parsed' / 'skipped' /
 *     'unmatched' per outcome.
 *
 * Returns the `MatchOutcomeDto` so the caller can branch on `parsed`
 * vs `skipped` vs `unmatched` and decide whether to yield a
 * canonical row downstream. The action does NOT itself write to the
 * transactions table — that responsibility stays on the existing
 * NormalizeStage → FingerprintComposer → RecordTransactions pipeline,
 * which the caller (ParseStage) plugs into via ReceiptSourceAdapter.
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
            // Defensive: the insertOrIgnore above MUST land a row.
            return MatchOutcomeDto::unmatched('persist_failed');
        }
        $rawId = $row->id;
        $fileImportId = is_numeric($rawId) ? (int) $rawId : 0;

        if ($inserted === 0 && $row->status !== 'fetched') {
            // Already processed by a prior drop — the matcher_key on
            // the existing row preserves the original outcome; do
            // not re-dispatch and do not yield a duplicate canonical
            // row to the caller.
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

    /**
     * Sanitise the Message-ID header value to fit the FileDrop blob
     * store's `[A-Za-z0-9._-]{1,200}` allow-list. Strips angle
     * brackets, collapses non-allowed characters to `-`, and bounds
     * the length so a pathological header value cannot blow up the
     * filename. Falls back to sha256(value) when the sanitised result
     * is empty.
     */
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

    /**
     * Parse the message's Date header into a `DateTimeImmutable`
     * suitable for the file_imports.internal_date column. Falls back
     * to the local clock on parse failure so the row still lands and
     * the matcher dispatch can flip it to `unmatched` for triage.
     */
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
