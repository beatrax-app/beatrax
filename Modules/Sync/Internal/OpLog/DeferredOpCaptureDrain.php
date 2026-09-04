<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\OpLog;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Psr\Log\LoggerInterface;
use Throwable;

// Turns the coordinates a keyless process left behind back into signed ops, on
// the first request that can sign. The value is read from the live row HERE and
// the HLC is stamped HERE, so a drained op says "this device's current truth,
// announced late" rather than replaying a value that has since moved on.
/**
 * @link ../../../../.docs/features/sync/a-mutation-a-keyless-process-cannot-sign.md#the-drain
 */
final readonly class DeferredOpCaptureDrain
{
    // Coordinates per tick. The tail is paid after the response, but it is
    // still the reader's process: a queue built up over a week of a locked
    // device is drained across several requests rather than one long one.
    private const int BATCH = 400;

    public function __construct(
        private Container $container,
        private DatabaseManager $db,
        private DeferredOpCaptures $queue,
        private StoredRowPlaintext $plaintext,
        private MergeRulesRegistry $rules,
        private LoggerInterface $log,
    ) {}

    // Returns the coordinates retired by this tick; 0 when nothing was owed or
    // no key was in reach. Never throws: the caller is a request tail, and a
    // coordinate left standing is taken again by the next request.
    public function drain(int $userId): int
    {
        $pending = $this->queue->pending($userId, self::BATCH);

        if ($pending === []) {
            return 0;
        }

        $writer = $this->signingWriter();

        if ($writer === null) {
            return 0;
        }

        $retired = 0;

        foreach ($this->groups($pending) as $group) {
            $retired += $this->replay($userId, $writer, $group);
        }

        return $retired;
    }

    // Consecutive entries for one row, so a create's columns are emitted by a
    // single writeCreateRow and the live row behind them is read once. Grouping
    // by first appearance instead would let a set recorded later jump ahead of
    // the create of a different row that was captured before it.
    /**
     * @param  list<array{id: int, table_name: string, pk: string, field: string, op_kind: string, delta: ?int}>  $pending
     * @return list<list<array{id: int, table_name: string, pk: string, field: string, op_kind: string, delta: ?int}>>
     */
    private function groups(array $pending): array
    {
        $groups = [];
        $current = [];
        $key = null;

        foreach ($pending as $entry) {
            $entryKey = $entry['table_name']."\0".$entry['pk'];

            if ($key !== null && $entryKey !== $key) {
                $groups[] = $current;
                $current = [];
            }

            $key = $entryKey;
            $current[] = $entry;
        }

        if ($current !== []) {
            $groups[] = $current;
        }

        return $groups;
    }

    /**
     * @param  list<array{id: int, table_name: string, pk: string, field: string, op_kind: string, delta: ?int}>  $group
     */
    private function replay(int $userId, OpLogWriter $writer, array $group): int
    {
        $table = $group[0]['table_name'];
        $pk = $group[0]['pk'];

        try {
            $row = $this->liveRow($table, $pk, $userId);

            $this->db->connection()->transaction(function () use ($writer, $group, $table, $pk, $row): void {
                $this->emit($writer, $group, $table, $pk, $row);
                $this->queue->forget(array_column($group, 'id'));
            });

            return count($group);
        } catch (Throwable $e) {
            // Left standing rather than retired: an unreadable sealed column or
            // a table this build has no schema for is a verdict on this attempt,
            // and the row is still there for the next one to try.
            $this->log->warning('DeferredOpCaptureDrain: a deferred coordinate could not be replayed.', [
                'user_id' => $userId,
                'table' => $table,
                'pk' => $pk,
                ...SafeExceptionContext::describe($e),
            ]);

            return 0;
        }
    }

    // The create goes first whatever order the coordinates were read in: a
    // set that reaches a peer ahead of the create naming its row is an op
    // against a row that is not there yet. A tombstone needs no row at all;
    // the other kinds describe a value, so a row that is gone has none left.
    /**
     * @param  list<array{id: int, table_name: string, pk: string, field: string, op_kind: string, delta: ?int}>  $group
     * @param  array<string, mixed>|null  $row
     */
    private function emit(OpLogWriter $writer, array $group, string $table, string $pk, ?array $row): void
    {
        $id = PersistedOpLogEntries::normalizePk($pk);
        $creates = [];

        foreach ($group as $entry) {
            if (DeferredOpKind::tryFrom($entry['op_kind']) === DeferredOpKind::Create && $row !== null
                && array_key_exists($entry['field'], $row)) {
                $creates[$entry['field']] = $row[$entry['field']];
            }
        }

        if ($creates !== []) {
            $writer->writeCreateRow($table, $id, $creates);
        }

        foreach ($group as $entry) {
            $this->emitOne($writer, $table, $id, $entry, $row);
        }
    }

    /**
     * @param  array{id: int, table_name: string, pk: string, field: string, op_kind: string, delta: ?int}  $entry
     * @param  array<string, mixed>|null  $row
     */
    private function emitOne(OpLogWriter $writer, string $table, int|string $id, array $entry, ?array $row): void
    {
        $kind = DeferredOpKind::tryFrom($entry['op_kind']);

        if ($kind === DeferredOpKind::Delete) {
            $writer->writeDelete($table, $id);

            return;
        }

        if ($row === null || ! array_key_exists($entry['field'], $row)) {
            return;
        }

        // A delta that did not survive as a positive number is dropped rather
        // than rounded up to one: writeIncrement refuses it outright, and a
        // count invented here is a count no peer should be asked to add.
        if ($kind === DeferredOpKind::Increment) {
            if ($entry['delta'] !== null && $entry['delta'] >= 1) {
                $writer->writeIncrement($table, $id, $entry['field'], $entry['delta']);
            }

            return;
        }

        if ($kind === DeferredOpKind::Set) {
            $writer->writeSet($table, $id, $entry['field'], $row[$entry['field']]);
        }
    }

    // Decrypted here, because the columns sealed at rest are re-sealed by the
    // writer under associated data of its own. Columns the registry keeps off
    // the wire are dropped rather than read: `users` mixes the reader's
    // settings with this device's own password and theme.
    /**
     * @return array<string, mixed>|null
     */
    private function liveRow(string $table, string $pk, int $userId): ?array
    {
        $connection = $this->db->connection();

        if (! $connection->getSchemaBuilder()->hasTable($table)) {
            return null;
        }

        $row = $connection->table($table)->where('id', PersistedOpLogEntries::normalizePk($pk))->first();

        if ($row === null) {
            return null;
        }

        /** @var array<string, mixed> $fields */
        $fields = (array) $row;

        foreach ($this->rules->columnsNeverOnTheWire($table) as $column) {
            unset($fields[$column]);
        }

        return $this->plaintext->fields($table, $fields, $userId);
    }

    // Null whenever this session cannot sign. Distinguished from the replay's
    // own failures because only one of the two is a verdict on the coordinate.
    private function signingWriter(): ?OpLogWriter
    {
        try {
            return $this->container->make(OpLogWriter::class);
        } catch (Throwable) {
            return null;
        }
    }
}
