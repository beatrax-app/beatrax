<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Services;

use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\JoinClause;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\EmailScan\Public\Dto\DiscoveredSenderDto;
use stdClass;
use Throwable;

/**
 * Public read API over discovered_senders, scoped to the rolling
 * promotion window.
 *
 * The /inboxes "Discovered senders" panel reads this surface to
 * decide which sender candidates surface to the user — only those
 * the daily DiscoveryScanJob saw at least MIN_OCCURRENCES times
 * within WITHIN_DAYS of today. A single-shot sender that turns up
 * once and never again deliberately stays below the threshold so
 * the panel never asks the user to make a call on a row that may
 * never reappear.
 *
 * Threshold defaults (CONTEXT.md D-135):
 *  - MIN_OCCURRENCES = 2 — two observations within the window is
 *    the floor for "this is a recurring sender, worth asking about".
 *  - WITHIN_DAYS = 90 — a quarter of recurring receipt traffic; long
 *    enough to catch monthly subscriptions, short enough to age out
 *    one-off promotional senders.
 *
 * The constants are exposed as method-default parameters so a future
 * UI toggle ("show all" mode) can pass relaxed values without changing
 * the query shape. The defaults match the panel's locked behaviour.
 *
 * candidatesForUser JOINs to `inboxes` on both `inbox_id` AND
 * `user_id` so a discovered row whose denormalised `user_id` somehow
 * disagrees with the parent inbox's `user_id` is dropped at the read
 * boundary. The write-side `PromoteDiscoveredSender` /
 * `DismissDiscoveredSender` actions already enforce the cross-user
 * 404 invariant; this is the read-side mirror.
 */
final class DiscoveredSenderQuery
{
    /**
     * Minimum number of observations within the rolling window before
     * a sender is surfaced in the panel.
     */
    public const MIN_OCCURRENCES = 2;

    /**
     * Number of days back from `now()` that count toward the
     * MIN_OCCURRENCES threshold. Observations older than this are
     * ignored (not deleted — the row stays for audit).
     */
    public const WITHIN_DAYS = 90;

    /**
     * Hard cap on rows returned. The panel paginates client-side; this
     * cap prevents a runaway-growth scenario where 1000+ discovered
     * candidates land in one render. 25 is enough for the panel to
     * stay scrollable without taking over the page.
     */
    public const PANEL_PAGE_SIZE = 25;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
    ) {}

    /**
     * @return list<DiscoveredSenderDto>
     */
    public function candidatesForUser(
        User $user,
        int $minOccurrences = self::MIN_OCCURRENCES,
        int $withinDays = self::WITHIN_DAYS,
    ): array {
        $threshold = $this->clock->now()->modify("-{$withinDays} days")->toDateTimeString();

        // Defense-in-depth: JOIN to inboxes on BOTH inbox_id AND
        // user_id so a candidate row whose denormalised user_id
        // somehow disagrees with the parent inboxes.user_id (a future
        // bug or a malicious foreign-key insert) is silently dropped
        // by the SQL filter rather than leaked into the UI. The
        // PromoteDiscoveredSender / DismissDiscoveredSender actions
        // already guard the write side; this guards the read side.
        $rows = $this->db->connection()
            ->table('discovered_senders')
            ->join('inboxes', function (JoinClause $join) use ($user): void {
                $join->on('inboxes.id', '=', 'discovered_senders.inbox_id')
                    ->where('inboxes.user_id', '=', $user->id);
            })
            ->where('discovered_senders.user_id', $user->id)
            ->where('discovered_senders.state', 'candidate')
            ->where('discovered_senders.occurrence_count', '>=', $minOccurrences)
            ->where('discovered_senders.last_seen_at', '>=', $threshold)
            ->orderBy('discovered_senders.occurrence_count', 'desc')
            ->orderBy('discovered_senders.last_seen_at', 'desc')
            ->limit(self::PANEL_PAGE_SIZE)
            ->select([
                'discovered_senders.id',
                'discovered_senders.user_id',
                'discovered_senders.inbox_id',
                'discovered_senders.sender_email',
                'discovered_senders.sender_name',
                'discovered_senders.occurrence_count',
                'discovered_senders.last_seen_at',
                'discovered_senders.state',
            ])
            ->get();

        $out = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $out[] = $this->makeDto($row);
        }

        return $out;
    }

    /**
     * Count panel-eligible candidates for the top-nav badge. Same
     * threshold as `candidatesForUser` so the badge value mirrors what
     * the panel renders — a count > 0 on the badge means at least one
     * row WILL appear on the panel.
     */
    public function candidatesCountForUser(
        User $user,
        int $minOccurrences = self::MIN_OCCURRENCES,
        int $withinDays = self::WITHIN_DAYS,
    ): int {
        $threshold = $this->clock->now()->modify("-{$withinDays} days")->toDateTimeString();

        return self::toInt(
            $this->db->connection()
                ->table('discovered_senders')
                ->where('user_id', $user->id)
                ->where('state', 'candidate')
                ->where('occurrence_count', '>=', $minOccurrences)
                ->where('last_seen_at', '>=', $threshold)
                ->count(),
        );
    }

    private function makeDto(stdClass $row): DiscoveredSenderDto
    {
        $rawSenderName = $row->sender_name ?? null;
        $senderName = is_string($rawSenderName) && $rawSenderName !== '' ? $rawSenderName : null;

        $lastSeenRaw = self::toString($row->last_seen_at ?? null);
        try {
            $lastSeen = new DateTimeImmutable($lastSeenRaw);
        } catch (Throwable) {
            $lastSeen = $this->clock->now()->toDateTimeImmutable();
        }

        return new DiscoveredSenderDto(
            id: self::toInt($row->id),
            userId: self::toInt($row->user_id),
            inboxId: self::toInt($row->inbox_id),
            senderEmail: self::toString($row->sender_email),
            senderName: $senderName,
            occurrenceCount: self::toInt($row->occurrence_count),
            lastSeenAt: $lastSeen,
            state: self::toString($row->state),
        );
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function toString(mixed $value): string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }
}
