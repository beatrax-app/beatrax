<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal;

use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\EmailScan\Public\Dto\ScanCursor;
use RuntimeException;

/**
 * @link ../../../.docs/features/email-scan/architecture.md
 */
final class InboxScanStateMachine
{
    private const BUSY_TIMEOUT_PRAGMA = 'PRAGMA busy_timeout = 5000';

    use CoercesScalars;

    // Per-attempt backoff schedule in seconds ([60s, 5min, 15min,
    // 1h]); indices past the end clamp to the final entry so a
    // runaway retry count cannot push the delay to infinity.
    /** @var list<int> */
    private const BACKOFF_SCHEDULE = [60, 300, 900, 3600];

    // Per-state allowed-target map; a transition not present here
    // raises InvalidStateTransitionException — there is no
    // "any state -> any state" escape hatch (see architecture.md for
    // why the re-entrant and 'error'/'needs_reauth' entries exist).
    /** @var array<string, list<string>> */
    private const ALLOWED_TRANSITIONS = [
        'idle' => ['idle', 'backfilling', 'scanning', 'needs_reauth', 'error'],
        'backfilling' => ['backfilling', 'idle', 'rate_limited', 'needs_reauth', 'error'],
        'scanning' => ['scanning', 'idle', 'rate_limited', 'needs_reauth', 'error'],
        'rate_limited' => ['backfilling', 'scanning', 'idle', 'needs_reauth', 'error'],
        'needs_reauth' => ['idle', 'needs_reauth'],
        'error' => ['idle', 'backfilling', 'scanning', 'needs_reauth', 'error'],
    ];

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
                throw new RuntimeException(
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

            // last_scan_at advances ONLY on success-shaped transitions
            // so the UI can surface "stuck since X" for a stalled
            // rate_limited / needs_reauth / error inbox.
            if (in_array($newStatus, ['idle', 'backfilling', 'scanning'], strict: true)) {
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
                throw new RuntimeException(
                    "InboxScanStateMachine: inbox_scan_state for inbox {$inboxId} folder INBOX not found.",
                );
            }

            $currentStatus = self::toString($row->status);
            $this->guardTransition($inboxId, $currentStatus, 'rate_limited');

            $newAttempts = self::toInt($row->retry_attempts) + 1;
            $now = $this->clock->now()->toDateTimeString();

            $connection->table('inbox_scan_state')
                ->where('inbox_id', $inboxId)
                ->where('folder', 'INBOX')
                ->update([
                    'status' => 'rate_limited',
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
                throw new RuntimeException(
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

    // Returns the seconds-to-wait for the given attempt index, clamped
    // to the BACKOFF_SCHEDULE bounds so a runaway retry count cannot
    // walk past the final entry.
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
                throw new RuntimeException(
                    "InboxScanStateMachine: inbox_scan_state for inbox {$inboxId} folder INBOX not found.",
                );
            }

            // Cross-checks the cursor provider against the inbox
            // row's provider, since a Gmail cursor must never land on
            // a Microsoft inbox row (a mismatch would otherwise
            // silently write the wrong cursor column).
            $inboxProvider = self::toString(
                $connection->table('inboxes')->where('id', $inboxId)->value('provider'),
            );
            if ($cursor->provider !== $inboxProvider) {
                throw new InvalidArgumentException(
                    "InboxScanStateMachine: cursor provider '{$cursor->provider}' does not match inbox {$inboxId} provider '{$inboxProvider}'.",
                );
            }

            $update = ['updated_at' => $this->clock->now()->toDateTimeString()];
            if ($cursor->provider === 'gmail' && $cursor->historyId !== null) {
                $update['last_history_id'] = $cursor->historyId;
            } elseif ($cursor->provider === 'microsoft' && $cursor->deltaLink !== null) {
                $update['last_delta_link'] = $cursor->deltaLink;
            }

            $connection->table('inbox_scan_state')
                ->where('inbox_id', $inboxId)
                ->where('folder', 'INBOX')
                ->update($update);
        });
    }

    // Writes (or clears, on null) the per-inbox backfill progress
    // payload under the same busy_timeout=5000 fence as the rest of
    // the lifecycle mutations, so the column stays in lockstep.
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
        $allowed = self::ALLOWED_TRANSITIONS[$currentStatus] ?? [];
        if (! in_array($newStatus, $allowed, strict: true)) {
            throw new InvalidStateTransitionException(
                "InboxScanStateMachine: inbox {$inboxId} transition '{$currentStatus}' → '{$newStatus}' is not allowed.",
            );
        }
    }
}
