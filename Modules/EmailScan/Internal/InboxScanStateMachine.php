<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal;

use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\EmailScan\Internal\Exceptions\ScanStateNotFoundException;
use Modules\EmailScan\Public\Dto\ScanCursor;
use Modules\EmailScan\Public\Enums\InboxScanStatus;
use Modules\EmailScan\Public\Enums\MailProvider;

final class InboxScanStateMachine
{
    private const BUSY_TIMEOUT_PRAGMA = 'PRAGMA busy_timeout = 5000';

    use CoercesScalars;

    // Indices past the end clamp to the final entry, so a runaway retry
    // count cannot push the delay past an hour.
    /** @var list<int> */
    private const BACKOFF_SCHEDULE = [60, 300, 900, 3600];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
    ) {}

    public function applyStatus(
        int $inboxId,
        string $newStatus,
        ?string $errorMessage = null,
    ): void {
        $this->db->connection()->transaction(function () use ($inboxId, $newStatus, $errorMessage): void {
            $connection = $this->db->connection();
            $connection->statement(self::BUSY_TIMEOUT_PRAGMA);

            $row = $connection->table('inbox_scan_state')
                ->where('inbox_id', $inboxId)
                ->where('folder', 'INBOX')
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                throw new ScanStateNotFoundException(
                    "InboxScanStateMachine: inbox_scan_state for inbox {$inboxId} folder INBOX not found.",
                );
            }

            $currentStatus = self::toString($row->status);
            $this->guardTransition($inboxId, $currentStatus, $newStatus);

            $now = $this->clock->now()->toDateTimeString();
            $update = [
                'status' => $newStatus,
                'error_message' => $errorMessage,
                'updated_at' => $now,
            ];

            // last_scan_at advances only on success-shaped transitions, so
            // the UI can say "stuck since X" for a stalled inbox.
            if (in_array($newStatus, [
                InboxScanStatus::Idle->value,
                InboxScanStatus::Backfilling->value,
                InboxScanStatus::Scanning->value,
            ], strict: true)) {
                $update['last_scan_at'] = $now;
            }

            $connection->table('inbox_scan_state')
                ->where('inbox_id', $inboxId)
                ->where('folder', 'INBOX')
                ->update($update);
        });
    }

    public function applyRateLimited(int $inboxId, int $retryAfterSeconds): void
    {
        $this->db->connection()->transaction(function () use ($inboxId, $retryAfterSeconds): void {
            $connection = $this->db->connection();
            $connection->statement(self::BUSY_TIMEOUT_PRAGMA);

            $row = $connection->table('inbox_scan_state')
                ->where('inbox_id', $inboxId)
                ->where('folder', 'INBOX')
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                throw new ScanStateNotFoundException(
                    "InboxScanStateMachine: inbox_scan_state for inbox {$inboxId} folder INBOX not found.",
                );
            }

            $currentStatus = self::toString($row->status);
            $this->guardTransition($inboxId, $currentStatus, InboxScanStatus::RateLimited->value);

            $newAttempts = self::toInt($row->retry_attempts) + 1;
            $now = $this->clock->now()->toDateTimeString();

            $connection->table('inbox_scan_state')
                ->where('inbox_id', $inboxId)
                ->where('folder', 'INBOX')
                ->update([
                    'status' => InboxScanStatus::RateLimited->value,
                    'retry_attempts' => $newAttempts,
                    'error_message' => "Retry after {$retryAfterSeconds}s.",
                    'updated_at' => $now,
                ]);
        });
    }

    public function resetRetryAttempts(int $inboxId): void
    {
        $this->db->connection()->transaction(function () use ($inboxId): void {
            $connection = $this->db->connection();
            $connection->statement(self::BUSY_TIMEOUT_PRAGMA);

            $row = $connection->table('inbox_scan_state')
                ->where('inbox_id', $inboxId)
                ->where('folder', 'INBOX')
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                throw new ScanStateNotFoundException(
                    "InboxScanStateMachine: inbox_scan_state for inbox {$inboxId} folder INBOX not found.",
                );
            }

            $connection->table('inbox_scan_state')
                ->where('inbox_id', $inboxId)
                ->where('folder', 'INBOX')
                ->update([
                    'retry_attempts' => 0,
                    'updated_at' => $this->clock->now()->toDateTimeString(),
                ]);
        });
    }

    public function backoffForAttempt(int $attempt): int
    {
        $maxIndex = count(self::BACKOFF_SCHEDULE) - 1;
        $idx = max(0, min($maxIndex, $attempt));

        return self::BACKOFF_SCHEDULE[$idx];
    }

    public function recordCursor(int $inboxId, ScanCursor $cursor): void
    {
        if ($cursor->isEmpty()) {
            return;
        }

        $this->db->connection()->transaction(function () use ($inboxId, $cursor): void {
            $connection = $this->db->connection();
            $connection->statement(self::BUSY_TIMEOUT_PRAGMA);

            $row = $connection->table('inbox_scan_state')
                ->where('inbox_id', $inboxId)
                ->where('folder', 'INBOX')
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                throw new ScanStateNotFoundException(
                    "InboxScanStateMachine: inbox_scan_state for inbox {$inboxId} folder INBOX not found.",
                );
            }

            // A Gmail cursor landing on a Microsoft inbox row would silently
            // write the wrong cursor column.
            $inboxProvider = self::toString(
                $connection->table('inboxes')->where('id', $inboxId)->value('provider'),
            );
            if ($cursor->provider !== $inboxProvider) {
                throw new InvalidArgumentException(
                    "InboxScanStateMachine: cursor provider '{$cursor->provider}' does not match inbox {$inboxId} provider '{$inboxProvider}'.",
                );
            }

            $update = ['updated_at' => $this->clock->now()->toDateTimeString()];
            if ($cursor->provider === MailProvider::Gmail->value && $cursor->historyId !== null) {
                $update['last_history_id'] = $cursor->historyId;
            } elseif ($cursor->provider === MailProvider::Microsoft->value && $cursor->deltaLink !== null) {
                $update['last_delta_link'] = $cursor->deltaLink;
            }

            $connection->table('inbox_scan_state')
                ->where('inbox_id', $inboxId)
                ->where('folder', 'INBOX')
                ->update($update);
        });
    }

    // Under the same busy_timeout=5000 fence as the other lifecycle writes.
    /**
     * @param  array{fetched_count: int, total_estimated: int, last_message_date: ?string}|null  $progress
     */
    public function recordBackfillProgress(int $inboxId, ?array $progress): void
    {
        $this->db->connection()->transaction(function () use ($inboxId, $progress): void {
            $connection = $this->db->connection();
            $connection->statement(self::BUSY_TIMEOUT_PRAGMA);

            $encoded = $progress === null
                ? null
                : json_encode($progress, JSON_THROW_ON_ERROR);

            $connection->table('inboxes')
                ->where('id', $inboxId)
                ->update([
                    'backfill_progress' => $encoded,
                    'updated_at' => $this->clock->now()->toDateTimeString(),
                ]);
        });
    }

    private function guardTransition(int $inboxId, string $currentStatus, string $newStatus): void
    {
        $current = InboxScanStatus::tryFrom($currentStatus);
        $allowed = $current === null
            ? []
            : array_map(static fn (InboxScanStatus $s): string => $s->value, $current->allowedNext());
        if (! in_array($newStatus, $allowed, strict: true)) {
            throw new InvalidStateTransitionException(
                "InboxScanStateMachine: inbox {$inboxId} transition '{$currentStatus}' → '{$newStatus}' is not allowed.",
            );
        }
    }
}
