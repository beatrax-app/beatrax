<?php

declare(strict_types=1);

namespace Modules\Budgets\Public\Services;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Sync\Public\Events\EnvelopeAssignmentMutated;
use Modules\Sync\Public\Events\EnvelopeMoveMutated;

// CarryoverQuery looks envelope rows up by their literal period_start, and
// period_start_day moves every boundary at once: without this pass the rows
// stay on disk matching no period the fold walks, and the plan reads as zero.
// Propagated as delete + create because period_start is create-only in sync.
final class EnvelopePeriodRekeyer
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Dispatcher $events,
        private readonly Clock $clock,
        private readonly PeriodQuery $periods,
        private readonly CurrentUser $currentUser,
    ) {}

    public function rekeyToCurrentPeriods(): void
    {
        $pending = [];

        $this->db->connection()->transaction(function () use (&$pending): void {
            $pending = [
                ...$this->rekeyAssignments(),
                ...$this->rekeyMoves(),
            ];
        });

        foreach ($pending as $event) {
            $this->events->dispatch($event);
        }
    }

    /**
     * @return list<EnvelopeAssignmentMutated>
     */
    private function rekeyAssignments(): array
    {
        $userId = $this->currentUser->user()->id;
        $connection = $this->db->connection();

        $rows = $connection->table('envelope_assignments')
            ->where('user_id', $userId)
            ->orderBy('id')
            ->get(['id', 'category_id', 'period_start', 'assigned_minor', 'currency', 'created_at']);

        $targets = $this->targets($rows);
        if ($targets === []) {
            return [];
        }

        // Two old keys landing on one period would break the (user, category,
        // period_start) UNIQUE, so the surviving row carries their sum. The
        // grouping covers rows that did not move as well, because one that
        // stayed put may be the row a moved one now collides with.
        $buckets = [];
        foreach ($rows as $row) {
            $key = $targets[(int) $row->id] ?? (string) $row->period_start;
            $bucket = $row->category_id.'|'.$key;
            $buckets[$bucket] ??= [
                'category_id' => (int) $row->category_id,
                'period_start' => $key,
                'assigned_minor' => 0,
                'currency' => (string) $row->currency,
                'created_at' => $row->created_at,
            ];
            $buckets[$bucket]['assigned_minor'] += (int) $row->assigned_minor;
        }

        $events = [];
        foreach ($rows as $row) {
            $events[] = new EnvelopeAssignmentMutated(
                assignmentId: (int) $row->id,
                userId: $userId,
                mutationType: 'delete',
            );
        }
        $connection->table('envelope_assignments')->where('user_id', $userId)->delete();

        $now = $this->clock->now()->toDateTimeString();
        foreach ($buckets as $bucket) {
            $id = (int) $connection->table('envelope_assignments')->insertGetId([
                'user_id' => $userId,
                'category_id' => $bucket['category_id'],
                'period_start' => $bucket['period_start'],
                'assigned_minor' => $bucket['assigned_minor'],
                'currency' => $bucket['currency'],
                'created_at' => $bucket['created_at'] ?? $now,
                'updated_at' => $now,
            ]);

            $events[] = new EnvelopeAssignmentMutated(
                assignmentId: $id,
                userId: $userId,
                mutationType: 'create',
                dirtyFields: [
                    'user_id' => $userId,
                    'category_id' => $bucket['category_id'],
                    'period_start' => $bucket['period_start'],
                    'assigned_minor' => $bucket['assigned_minor'],
                    'currency' => $bucket['currency'],
                ],
            );
        }

        return $events;
    }

    /**
     * @return list<EnvelopeMoveMutated>
     */
    private function rekeyMoves(): array
    {
        $userId = $this->currentUser->user()->id;
        $connection = $this->db->connection();

        $rows = $connection->table('envelope_moves')
            ->where('user_id', $userId)
            ->orderBy('id')
            ->get([
                'id',
                'category_id',
                'counterpart_category_id',
                'period_start',
                'amount_minor',
                'currency',
                'kind',
                'memo',
                'move_group_id',
                'created_at',
            ]);

        $targets = $this->targets($rows);
        if ($targets === []) {
            return [];
        }

        $events = [];
        $now = $this->clock->now()->toDateTimeString();

        // Append-only ledger: each row is re-created one for one, so the two
        // rows of a move stay paired on their shared move_group_id.
        foreach ($rows as $row) {
            $key = $targets[(int) $row->id] ?? null;
            if ($key === null) {
                continue;
            }

            $events[] = new EnvelopeMoveMutated(
                moveId: (int) $row->id,
                userId: $userId,
                mutationType: 'delete',
            );
            $connection->table('envelope_moves')->where('id', $row->id)->delete();

            $fields = [
                'user_id' => $userId,
                'category_id' => (int) $row->category_id,
                'counterpart_category_id' => (int) $row->counterpart_category_id,
                'period_start' => $key,
                'amount_minor' => (int) $row->amount_minor,
                'currency' => (string) $row->currency,
                'kind' => (string) $row->kind,
                'move_group_id' => $row->move_group_id,
            ];

            $id = (int) $connection->table('envelope_moves')->insertGetId([
                ...$fields,
                'memo' => $row->memo,
                'created_at' => $row->created_at ?? $now,
                'updated_at' => $now,
            ]);

            $events[] = new EnvelopeMoveMutated(
                moveId: $id,
                userId: $userId,
                mutationType: 'create',
                dirtyFields: $fields,
            );
        }

        return $events;
    }

    // Only the rows that actually move. A stored value that is no longer a
    // plain date has no period at all, so it is left where it is rather than
    // guessed at.
    /**
     * @param  Collection<int, \stdClass>  $rows
     * @return array<int, string>
     */
    private function targets($rows): array
    {
        $moved = [];
        $resolved = [];

        foreach ($rows as $row) {
            $stored = (string) $row->period_start;

            if (! array_key_exists($stored, $resolved)) {
                $period = $this->periods->containingDate($stored);
                $resolved[$stored] = $period?->start->toDateString();
            }

            $target = $resolved[$stored];
            if ($target !== null && $target !== $stored) {
                $moved[(int) $row->id] = $target;
            }
        }

        return $moved;
    }
}
