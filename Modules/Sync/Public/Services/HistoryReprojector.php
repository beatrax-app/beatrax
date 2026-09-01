<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Services;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Search\Public\Contracts\SearchIndexWriterContract;
use Modules\Sync\Internal\Config\CoveredTableOrder;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Crypto\GdkEpoch;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\Merge\RowHistoryPolicy;
use Modules\Sync\Internal\OpLog\PersistedOpLogEntries;
use Modules\Sync\Internal\OpLog\QuarantineReason;
use Modules\Sync\Internal\OpLog\SyncBacklogState;
use Throwable;

/**
 * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md#telling-not-yet-openable-apart-from-never-openable-here
 */
final readonly class HistoryReprojector
{
    // Deep enough for a child, its parent and its grandparent, which is as
    // far as the covered schema nests.
    private const int PARENT_WALK_LIMIT = 3;

    // One pass does not have to finish the sweep; what it leaves, the next
    // one takes. The bound is what keeps a large quarantine off the request.
    private const int SETTLED_SWEEP_LIMIT = 500;

    public function __construct(
        private DatabaseManager $db,
        private PersistedOpLogEntries $entries,
        private DeviceRegistryService $registry,
        private GdkKeyringService $keyring,
        private Container $container,
    ) {}

    // Readable with no app-lock key at all, which is what makes it usable as
    // the gate: a caller can ask "did key material move since my last pass?"
    // before deciding whether the pass is worth attempting.
    public function keyringFingerprint(int $userId): ?string
    {
        return $this->keyring->keyringFingerprint($userId);
    }

    // Replays ONLY the rows a quarantined entry names, through the same
    // replayer the live drain uses — no delete, no trigger drop, no whole-log
    // re-projection. Returns the number of rows replayed.
    /**
     * @throws Throwable re-thrown from `OpLogReplayer::replay()`.
     */
    public function replayQuarantined(int $userId, Session $session, ?string $since, ?string $lastFingerprint): int
    {
        // Spent refusals first: a row already here needs no replay, and the
        // reasons that are not recoverable never reach the pass below, so this
        // is the only thing that ever clears one.
        $this->clearSettled($userId);

        $rows = $this->rowsWorthReplaying($userId, $session, $since, $lastFingerprint);
        if ($rows === []) {
            return 0;
        }

        $entries = $this->entries->forRows($userId, $this->withParentsNamed($rows, $userId));
        if ($entries === []) {
            return 0;
        }

        // forRows() already fetched every op of every row named, which is
        // exactly what a strategy has to resolve over.
        $this->buildReplayer($userId)->replay($entries, $userId, RowHistoryPolicy::AsGiven);
        $this->clearSettled($userId);

        return count($rows);
    }

    // A create refused because the row could not be inserted is spent once the
    // row is here. Nothing ever cleared one, so a phone reported 60 refusals
    // for rows it already held. A field op held for an unreadable value is NOT
    // spent by its row existing — that row was never the thing in question.
    private function clearSettled(int $userId): void
    {
        $held = $this->db->connection()->table('op_log_quarantine')
            ->where('user_id', $userId)
            ->whereIn('reason', QuarantineReason::createRefusals())
            ->distinct()
            ->limit(self::SETTLED_SWEEP_LIMIT)
            ->get(['table_name', 'pk']);

        foreach ($held as $row) {
            $table = is_string($row->table_name ?? null) ? $row->table_name : '';
            $pk = isset($row->pk) && (is_string($row->pk) || is_numeric($row->pk)) ? (string) $row->pk : '';

            if ($table === '' || $pk === '' || ! $this->rowIsHere(['table' => $table, 'pk' => $pk], $userId)) {
                continue;
            }

            $this->db->connection()->table('op_log_quarantine')
                ->where('user_id', $userId)
                ->where('table_name', $table)
                ->where('pk', $pk)
                ->delete();
        }
    }

    // Here under the id the peer used, or under the one this device minted for
    // the same row. Both mean the refusal is spent.
    /**
     * @param  array{table: string, pk: string}  $row
     */
    private function rowIsHere(array $row, int $userId): bool
    {
        try {
            $connection = $this->db->connection();

            if ($connection->table($row['table'])->where('id', $row['pk'])->exists()) {
                return true;
            }

            $local = $connection->table('op_log_row_aliases')
                ->where('user_id', $userId)
                ->where('table_name', $row['table'])
                ->where('remote_id', $row['pk'])
                ->value('local_id');

            return is_string($local) && $connection->table($row['table'])->where('id', $local)->exists();
        } catch (Throwable) {
            return false;
        }
    }

    // What a reader should be told. AwaitingKey outranks Deferred because it
    // is the half that will not clear on its own, and a screen that reported
    // only the self-healing half would leave the stuck one invisible.
    public function backlogState(int $userId, Session $session, ?string $since, ?string $lastFingerprint): SyncBacklogState
    {
        if (! $this->anyRecoverableQuarantine($userId)) {
            return SyncBacklogState::None;
        }

        if ($this->awaitingRows($userId, $session)->exists()) {
            return SyncBacklogState::AwaitingKey;
        }

        return $this->rowsWorthReplaying($userId, $session, $since, $lastFingerprint) === []
            ? SyncBacklogState::None
            : SyncBacklogState::Deferred;
    }

    // A row is worth replaying when it arrived since the last pass, or when key
    // material has moved since — the one event that can make an unopenable entry
    // openable. Entries under an epoch this device does not hold are excluded
    // from both, which stops a sealed entry driving a replay every sync.
    /**
     * @return list<array{table: string, pk: string}>
     */
    private function rowsWorthReplaying(int $userId, Session $session, ?string $since, ?string $lastFingerprint): array
    {
        $keyringMoved = $this->keyringFingerprint($userId) !== $lastFingerprint;

        $query = $this->openableRows($userId, $session);
        if (! $keyringMoved && $since !== null) {
            $query->where('created_at', '>', $since);
        }

        $rows = [];
        $seen = [];

        foreach ($query->get(['table_name', 'pk']) as $row) {
            $table = is_string($row->table_name ?? null) ? $row->table_name : '';
            $pk = isset($row->pk) && (is_string($row->pk) || is_numeric($row->pk)) ? (string) $row->pk : '';
            if ($table === '' || $pk === '' || isset($seen[$table.':'.$pk])) {
                continue;
            }

            $seen[$table.':'.$pk] = true;
            $rows[] = ['table' => $table, 'pk' => $pk];
        }

        return $rows;
    }

    // A row held for a missing reference NAMES the row that is missing, whose
    // own ops sit in the log unreplayed. Replaying only the held row re-ran the
    // same failure; pulling its parents in is what lets the parent land, or
    // records the id pair when it is already here under a locally minted id.
    /**
     * @param  list<array{table: string, pk: string}>  $rows
     * @return list<array{table: string, pk: string}>
     */
    private function withParentsNamed(array $rows, int $userId): array
    {
        $order = new CoveredTableOrder($this->db, new MergeRulesRegistry);
        $seen = [];

        foreach ($rows as $row) {
            $seen[$row['table'].':'.$row['pk']] = true;
        }

        // A grandparent can be missing too, so the walk follows what it finds.
        // Bounded because a cycle in the foreign keys would otherwise not end.
        $frontier = $rows;

        for ($depth = 0; $depth < self::PARENT_WALK_LIMIT && $frontier !== []; $depth++) {
            $next = [];

            foreach ($frontier as $row) {
                foreach ($this->parentsNamedBy($order, $row, $userId) as $parent) {
                    if (isset($seen[$parent['table'].':'.$parent['pk']])) {
                        continue;
                    }

                    $seen[$parent['table'].':'.$parent['pk']] = true;
                    $rows[] = $parent;
                    $next[] = $parent;
                }
            }

            $frontier = $next;
        }

        return $rows;
    }

    // The parent rows one held row points at, read off its own create ops
    // rather than off the table — the row is held precisely because it is not
    // in the table.
    /**
     * @param  array{table: string, pk: string}  $row
     * @return list<array{table: string, pk: string}>
     */
    private function parentsNamedBy(CoveredTableOrder $order, array $row, int $userId): array
    {
        try {
            $parents = $order->parentColumns($row['table']);
        } catch (Throwable) {
            return [];
        }

        unset($parents['user_id']);

        if ($parents === []) {
            return [];
        }

        $named = [];

        $entries = $this->db->connection()->table('op_log_entries')
            ->where('user_id', $userId)
            ->where('table_name', $row['table'])
            ->where('pk', $row['pk'])
            ->whereIn('field', array_keys($parents))
            ->get(['field', 'value']);

        foreach ($entries as $entry) {
            $field = is_string($entry->field ?? null) ? $entry->field : '';
            $parent = $parents[$field] ?? null;
            $value = is_string($entry->value ?? null) ? json_decode($entry->value, true) : null;

            if ($parent === null || (! is_int($value) && ! is_string($value)) || (string) $value === '') {
                continue;
            }

            $named[] = ['table' => $parent, 'pk' => (string) $value];
        }

        return $named;
    }

    // A null epoch is a refusal rather than a failed decrypt — the codec
    // declined to seal because no key was held — so a key alone undoes it and
    // there is no epoch to match against.
    private function openableRows(int $userId, Session $session): Builder
    {
        $held = $this->heldEpochIds($userId, $session);

        return $this->recoverableQuarantine($userId)
            ->where(static function (Builder $q) use ($held): void {
                $q->whereNull('gdk_epoch')->orWhereIn('gdk_epoch', $held);
            });
    }

    // Narrowed to the reasons a key actually undoes: this drives the
    // "waiting for a key" line, and a row held because its parent had not
    // landed would otherwise be given a cause that is not its own.
    private function awaitingRows(int $userId, Session $session): Builder
    {
        $held = $this->heldEpochIds($userId, $session);

        return $this->recoverableQuarantine($userId)
            ->whereIn('reason', QuarantineReason::keyRecoverable())
            ->whereNotNull('gdk_epoch')
            ->whereNotIn('gdk_epoch', $held);
    }

    // The cheap half of the question, for a caller deciding whether the exact
    // one is worth the keyring read. Epoch-blind on purpose: it answers "has
    // anything arrived that no pass has looked at", one seek on the index this
    // table already carries.
    public function hasUnexaminedQuarantine(int $userId, ?string $since): bool
    {
        $query = $this->recoverableQuarantine($userId);

        if ($since !== null) {
            $query->where('created_at', '>', $since);
        }

        return $query->exists();
    }

    private function anyRecoverableQuarantine(int $userId): bool
    {
        return $this->recoverableQuarantine($userId)->exists();
    }

    private function recoverableQuarantine(int $userId): Builder
    {
        return $this->db->connection()
            ->table('op_log_quarantine')
            ->where('user_id', $userId)
            ->whereIn('reason', QuarantineReason::recoverable());
    }

    /**
     * @return list<int>
     */
    private function heldEpochIds(int $userId, Session $session): array
    {
        try {
            $epochs = $this->keyring->loadKeyring($userId, $session)->epochs();
        } catch (Throwable) {
            return [];
        }

        return array_map(static fn (GdkEpoch $epoch): int => $epoch->epochId, $epochs);
    }

    // Built the way SyncWebSocketHandler builds it — the confirmed-device key
    // map read for this user explicitly, never the container's idea of who is
    // signed in, because this runs where there may be no request at all.
    private function buildReplayer(int $userId): OpLogReplayer
    {
        return new OpLogReplayer(
            db: $this->db,
            deviceKeys: $this->registry->deviceKeys($userId),
            rules: $this->container->make(MergeRulesRegistry::class),
            searchWriter: $this->container->bound(SearchIndexWriterContract::class)
                ? $this->container->make(SearchIndexWriterContract::class)
                : null,
        );
    }
}
