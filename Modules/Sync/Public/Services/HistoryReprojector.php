<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Services;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Search\Public\Contracts\SearchIndexWriterContract;
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
        $rows = $this->rowsWorthReplaying($userId, $session, $since, $lastFingerprint);
        if ($rows === []) {
            return 0;
        }

        $entries = $this->entries->forRows($userId, $rows);
        if ($entries === []) {
            return 0;
        }

        // forRows() already fetched every op of every row named, which is
        // exactly what a strategy has to resolve over.
        $this->buildReplayer($userId)->replay($entries, $userId, RowHistoryPolicy::AsGiven);

        return count($rows);
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
