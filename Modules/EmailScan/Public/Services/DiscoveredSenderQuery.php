<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Services;

use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\JoinClause;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\EmailScan\Public\Dto\DiscoveredSenderDto;
use stdClass;
use Throwable;

final class DiscoveredSenderQuery
{
    use CoercesScalars;

    public const MIN_OCCURRENCES = 2;

    // Older observations are ignored, not deleted — the row stays for audit.
    public const WITHIN_DAYS = 90;

    // The panel paginates client-side, so this bounds the render.
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

        // Joined on inbox_id AND user_id, so a candidate whose denormalised
        // user_id disagrees with its parent inbox is dropped in SQL rather
        // than leaked into the UI.
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

    // Same threshold as candidatesForUser, so a non-zero badge always means
    // at least one row is actually on the panel.
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
}
