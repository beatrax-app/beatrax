<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Services;

use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\JoinClause;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\EmailScan\Public\Dto\InboxHealthDto;
use stdClass;

// Public read API over inboxes + inbox_scan_state: hydrates each
// inbox row into an InboxHealthDto via a LEFT JOIN on the default
// INBOX folder's scan-state; findForUser returns null for another
// user's row so the HTTP layer translates it into a 404.
final class InboxQuery
{
    use CoercesScalars;

    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * @return list<InboxHealthDto>
     */
    public function forCurrentUser(User $user): array
    {
        $rows = $this->db->connection()
            ->table('inboxes')
            ->leftJoin('inbox_scan_state', function ($join): void {
                /** @var JoinClause $join */
                $join->on('inbox_scan_state.inbox_id', '=', 'inboxes.id')
                    ->where('inbox_scan_state.folder', '=', 'INBOX');
            })
            ->where('inboxes.user_id', $user->id)
            ->orderBy('inboxes.created_at', 'asc')
            ->orderBy('inboxes.id', 'asc')
            ->select([
                'inboxes.id as id',
                'inboxes.user_id as user_id',
                'inboxes.provider as provider',
                'inboxes.email as email',
                'inboxes.backfill_window_months as backfill_window_months',
                'inboxes.backfill_progress as backfill_progress',
                'inbox_scan_state.last_scan_at as last_scan_at',
                'inbox_scan_state.status as status',
                'inbox_scan_state.retry_attempts as retry_attempts',
                'inbox_scan_state.error_message as error_message',
            ])
            ->get();

        $out = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $out[] = $this->makeDto($row);
        }

        return $out;
    }

    public function findForUser(int $inboxId, User $user): ?InboxHealthDto
    {
        $row = $this->db->connection()
            ->table('inboxes')
            ->leftJoin('inbox_scan_state', function ($join): void {
                /** @var JoinClause $join */
                $join->on('inbox_scan_state.inbox_id', '=', 'inboxes.id')
                    ->where('inbox_scan_state.folder', '=', 'INBOX');
            })
            ->where('inboxes.id', $inboxId)
            ->where('inboxes.user_id', $user->id)
            ->select([
                'inboxes.id as id',
                'inboxes.user_id as user_id',
                'inboxes.provider as provider',
                'inboxes.email as email',
                'inboxes.backfill_window_months as backfill_window_months',
                'inboxes.backfill_progress as backfill_progress',
                'inbox_scan_state.last_scan_at as last_scan_at',
                'inbox_scan_state.status as status',
                'inbox_scan_state.retry_attempts as retry_attempts',
                'inbox_scan_state.error_message as error_message',
            ])
            ->first();

        if ($row === null) {
            return null;
        }

        /** @var stdClass $row */
        return $this->makeDto($row);
    }

    public function reviewBadgeCount(User $user): int
    {
        $candidates = self::toInt(
            $this->db->connection()
                ->table('discovered_senders')
                ->where('user_id', $user->id)
                ->where('state', 'candidate')
                ->count(),
        );

        $reauth = self::toInt(
            $this->db->connection()
                ->table('inbox_scan_state')
                ->where('user_id', $user->id)
                ->where('status', 'needs_reauth')
                ->count(),
        );

        return $candidates + $reauth;
    }

    private function makeDto(stdClass $row): InboxHealthDto
    {
        [$fetched, $totalEstimated] = $this->parseBackfillProgress($row->backfill_progress ?? null);

        $status = self::toString($row->status ?? null);
        if ($status === '') {
            // LEFT JOIN miss — the inbox row exists but the scan-state
            // row has not been inserted yet (transient window between
            // OAuth callback and first state-machine call). Treat as
            // idle.
            $status = 'idle';
        }

        return new InboxHealthDto(
            inboxId: self::toInt($row->id),
            userId: self::toInt($row->user_id),
            provider: self::toString($row->provider),
            email: self::toString($row->email),
            backfillWindowMonths: self::toInt($row->backfill_window_months),
            lastScanAt: $this->parseLastScanAt($row->last_scan_at ?? null),
            status: $status,
            retryAttempts: self::toInt($row->retry_attempts ?? 0),
            errorMessage: $this->toNullableString($row->error_message ?? null),
            backfillFetchedCount: $fetched,
            backfillTotalEstimated: $totalEstimated,
        );
    }

    /**
     * @return array{0: ?int, 1: ?int}
     */
    private function parseBackfillProgress(mixed $raw): array
    {
        if (! is_string($raw) || $raw === '') {
            return [null, null];
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [null, null];
        }

        $fetched = isset($decoded['fetched_count']) && is_numeric($decoded['fetched_count'])
            ? (int) $decoded['fetched_count']
            : null;
        $totalEstimated = isset($decoded['total_estimated']) && is_numeric($decoded['total_estimated'])
            ? (int) $decoded['total_estimated']
            : null;

        return [$fetched, $totalEstimated];
    }

    private function parseLastScanAt(mixed $raw): ?DateTimeImmutable
    {
        $lastScanRaw = self::toString($raw);
        if ($lastScanRaw === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($lastScanRaw);
        } catch (\Throwable) {
            return null;
        }
    }

    private function toNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return self::toStringOrNull($value);
    }
}
