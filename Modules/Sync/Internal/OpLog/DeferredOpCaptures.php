<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\OpLog;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\Instant;
use Psr\Log\LoggerInterface;

// The queue of mutations a process holding no signing key could not put on the
// wire. It holds WHERE a change happened and never WHAT it was: the value is
// re-read from the live row at drain time, so nothing sealed at rest is copied
// into a second table in the clear.
/**
 * @link ../../../../.docs/features/sync/a-mutation-a-keyless-process-cannot-sign.md
 */
final class DeferredOpCaptures
{
    // Past this the queue stops paying for itself: a whole-database backfill
    // walks every covered table in slices and reaches the same peer state, and
    // it is the mechanism that already exists for "this device owes everything".
    public const int MAX_PENDING_ENTRIES = 10_000;

    // Read once per process and then carried, because record() is called on
    // every mutation of a locked device and a COUNT per write would make the
    // queue cost grow with the length of the lock.
    private ?int $pending = null;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        private readonly BackfillProgress $progress,
        private readonly LoggerInterface $log,
    ) {}

    public function record(
        int $userId,
        string $table,
        int|string $pk,
        string $field,
        DeferredOpKind $kind,
        ?int $delta = null,
    ): void {
        if ($this->atCapacity($userId)) {
            return;
        }

        $coordinate = [
            'user_id' => $userId,
            'table_name' => $table,
            'pk' => (string) $pk,
            'field' => $field,
            'op_kind' => $kind->value,
        ];

        $inserted = $kind === DeferredOpKind::Increment
            ? $this->accumulate($coordinate, $delta ?? 0)
            : $this->remember($coordinate);

        if ($inserted) {
            $this->pending = ($this->pending ?? 0) + 1;
        }
    }

    public function hasPending(int $userId): bool
    {
        return $this->queue()->where('user_id', $userId)->exists();
    }

    // Insertion order is capture order, and the drain depends on it: a row's
    // create has to reach a peer before the sets that followed it locally.
    /**
     * @return list<array{id: int, table_name: string, pk: string, field: string, op_kind: string, delta: ?int}>
     */
    public function pending(int $userId, int $limit): array
    {
        $rows = $this->queue()
            ->where('user_id', $userId)
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'table_name', 'pk', 'field', 'op_kind', 'delta']);

        $pending = [];

        foreach ($rows as $row) {
            /** @var array<string, mixed> $entry */
            $entry = (array) $row;

            $pending[] = [
                'id' => is_numeric($entry['id'] ?? null) ? (int) $entry['id'] : 0,
                'table_name' => is_string($entry['table_name'] ?? null) ? $entry['table_name'] : '',
                'pk' => is_scalar($entry['pk'] ?? null) ? (string) $entry['pk'] : '',
                'field' => is_string($entry['field'] ?? null) ? $entry['field'] : '',
                'op_kind' => is_string($entry['op_kind'] ?? null) ? $entry['op_kind'] : '',
                'delta' => is_numeric($entry['delta'] ?? null) ? (int) $entry['delta'] : null,
            ];
        }

        return $pending;
    }

    /**
     * @param  list<int>  $ids
     */
    public function forget(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $this->queue()->whereIn('id', $ids)->delete();

        $this->pending = $this->pending === null ? null : max(0, $this->pending - count($ids));
    }

    // A g_counter column stores the total merged across every device, so the
    // delta this device just added is unrecoverable from the row afterwards.
    // It is the one quantity kept here, and it is a bare count of occurrences
    // on a field the sensitive-column registry does not seal.
    /**
     * @param  array<string, mixed>  $coordinate
     */
    private function accumulate(array $coordinate, int $delta): bool
    {
        if ($this->queue()->where($coordinate)->increment('delta', $delta) > 0) {
            return false;
        }

        if ($this->insert($coordinate, $delta) > 0) {
            return true;
        }

        // Lost the insert to a second process between the two statements, so
        // the delta this call carries is not on the row it now shares.
        $this->queue()->where($coordinate)->increment('delta', $delta);

        return false;
    }

    /**
     * @param  array<string, mixed>  $coordinate
     */
    private function remember(array $coordinate): bool
    {
        return $this->insert($coordinate, null) > 0;
    }

    /**
     * @param  array<string, mixed>  $coordinate
     */
    private function insert(array $coordinate, ?int $delta): int
    {
        return $this->queue()->insertOrIgnore([
            ...$coordinate,
            'delta' => $delta,
            'captured_at' => Instant::zulu($this->clock->now()),
        ]);
    }

    // Full means the coordinates are no longer the cheaper description of what
    // this device owes. Opening the pre-sync walk hands the whole job to the
    // resumable backfill instead, which the request tail already drives.
    private function atCapacity(int $userId): bool
    {
        $this->pending ??= $this->queue()->where('user_id', $userId)->count();

        if ($this->pending < self::MAX_PENDING_ENTRIES) {
            return false;
        }

        if (! $this->progress->isOpen($userId)) {
            $this->progress->open($userId);

            $this->log->warning('DeferredOpCaptures: queue full; owing a whole-database backfill instead.', [
                'user_id' => $userId,
                'pending' => $this->pending,
            ]);
        }

        return true;
    }

    private function queue(): Builder
    {
        return $this->db->connection()->table('deferred_op_captures');
    }
}
