<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Merge;

use Illuminate\Database\DatabaseManager;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\OpLog\PersistedOpLogEntries;
use Modules\Sync\Internal\OpLog\QuarantineReason;

// The creates `StrandedCreates` reports as unplaced, taken again as the device
// that sent them sent them. Nothing else on the device ever asks for these: the
// catch-up watermark is past them and `RetriedCollisionCreates` is driven by
// holds they never got, so the log is the only record they existed.
/**
 * @link ../../../../.docs/features/sync/architecture.md#rows-the-log-holds-and-the-table-does-not
 */
final readonly class StrandedCreateRepair
{
    public function __construct(
        private DatabaseManager $db,
        private StrandedCreates $stranded,
        private PersistedOpLogEntries $entries,
        private OpLogQuarantine $quarantine,
    ) {}

    // Only `unplaced`, which is the census's own word for a row a repair could
    // still place: `removedHere` is a row this device deleted and `held` a
    // verdict no later state undoes, so neither is addressable and neither is
    // reachable from here whatever table is named.
    /**
     * @return list<array{pk: string, devices: list<string>, naturalKey: string|null, action: 'insert'|'alias'|'refused'}>
     */
    public function plan(string $table, int $userId): array
    {
        $claimed = [];
        $plans = [];

        foreach ($this->stranded->groups($table, $userId) as $group) {
            if ($group['cause'] !== 'unplaced') {
                continue;
            }

            $key = $group['naturalKey'];
            $plans[] = [
                'pk' => $group['pk'],
                'devices' => $group['devices'],
                'naturalKey' => $key,
                'action' => self::actionFor($key, $claimed),
            ];

            if ($key !== null) {
                $claimed[$key] = true;
            }
        }

        return $plans;
    }

    // A row the natural key cannot identify is reported and skipped, never
    // guessed at: `RehomedCreate` refuses the same payload for the same
    // reason, so a repair that inserted it anyway would write a row every
    // later replay of the same create inserts another copy of.
    /**
     * @param  array<string, true>  $claimed
     * @return 'insert'|'alias'|'refused'
     */
    private static function actionFor(?string $key, array $claimed): string
    {
        if ($key === null) {
            return 'refused';
        }

        // Two stranded creates carrying one natural key are one row, so the
        // second finds the twin the first has just inserted and records the
        // alias instead — the answer `AlreadyPresentCreate` gives it.
        return isset($claimed[$key]) ? 'alias' : 'insert';
    }

    // Handed to the replayer per DEVICE, and as creates alone. A pk two devices
    // minted holds two rows' histories under one id, so replaying every op
    // there hands the merge the row already sitting at it; only the author
    // whose create went nowhere is taken again.
    /**
     * @param  list<array{pk: string, devices: list<string>, naturalKey: string|null, action: string}>  $plans
     * @return int Rows the table holds afterwards that it did not hold before.
     */
    public function replay(array $plans, OpLogReplayer $replayer, string $table, int $userId): int
    {
        $before = count($this->plan($table, $userId));

        foreach ($this->byDevice($plans, $table) as $deviceId => $rows) {
            $entries = $this->entries->createsFromDevice($userId, $rows, $deviceId);

            if ($entries !== []) {
                $replayer->replay($entries, $userId, RowHistoryPolicy::AsGiven);
            }
        }

        return $before - count($this->plan($table, $userId));
    }

    // The coordinate the retry needs: `RetriedCollisionCreates` reads holds,
    // and a create refused by a build predating `RehomedCreate` left none.
    // Written where no key is held, so the row is placed by the pass that can
    // seal it rather than by a console that would write it unreadable for good.
    /**
     * @param  list<array{pk: string, devices: list<string>, naturalKey: string|null, action: string}>  $plans
     * @return array{written: int, owed: int} Holds written now, and how many creates are inside the pass's reach afterwards.
     */
    public function hold(array $plans, string $table, int $userId, string $now): array
    {
        $written = 0;
        $owed = 0;

        foreach ($this->byDevice($plans, $table) as $deviceId => $rows) {
            foreach ($rows as $row) {
                $written += $this->holdOne($table, $row['pk'], $deviceId, $userId, $now);
                $owed += $this->alreadyHeld($table, $row['pk'], $deviceId, $userId) ? 1 : 0;
            }
        }

        return ['written' => $written, 'owed' => $owed];
    }

    // Counted by reading the hold back, never by the write returning. A
    // quarantine write is best-effort by design -- replay must continue whether
    // or not the audit row lands -- so a swallowed failure would otherwise be
    // reported as a coordinate that is not there, which is the worst answer.
    private function holdOne(string $table, string $pk, string $deviceId, int $userId, string $now): int
    {
        if ($this->alreadyHeld($table, $pk, $deviceId, $userId)) {
            return 0;
        }

        $arriving = self::latestOf($this->entries->createsFromDevice($userId, [['table' => $table, 'pk' => $pk]], $deviceId));

        if ($arriving === null) {
            return 0;
        }

        $this->quarantine->record($arriving, QuarantineReason::PrimaryKeyCollision, $now);

        return $this->alreadyHeld($table, $pk, $deviceId, $userId) ? 1 : 0;
    }

    // Any hold at all on the create, not only a collision one: a row already
    // inside the recovery pass's reach needs no second coordinate, and writing
    // one per run would grow the audit table every time this is asked.
    /**
     * @phpstan-impure
     */
    private function alreadyHeld(string $table, string $pk, string $deviceId, int $userId): bool
    {
        return $this->db->connection()->table('op_log_quarantine')
            ->where('user_id', $userId)
            ->where('table_name', $table)
            ->where('pk', $pk)
            ->where('device_id', $deviceId)
            ->where('op_log_quarantine.op_type', OpType::CreateRow->value)
            ->exists();
    }

    // The newest entry of the group, which is the one `AlreadyPresentCreate`
    // records a refusal under: the oldest is whichever field the peer wrote
    // first, and a hold naming it says less about when the row was owed.
    /**
     * @param  list<OpLogEntry>  $entries
     */
    private static function latestOf(array $entries): ?OpLogEntry
    {
        $latest = null;

        foreach ($entries as $entry) {
            if ($latest === null || [$entry->hlcL, $entry->hlcC] > [$latest->hlcL, $latest->hlcC]) {
                $latest = $entry;
            }
        }

        return $latest;
    }

    // Grouped by the author whose create went nowhere, because that is what
    // separates the two rows sharing an id. A plan the natural key could not
    // identify is left out of both paths rather than carried into one.
    /**
     * @param  list<array{pk: string, devices: list<string>, naturalKey: string|null, action: string}>  $plans
     * @return array<string, list<array{table: string, pk: string}>>
     */
    private function byDevice(array $plans, string $table): array
    {
        $rows = [];

        foreach ($plans as $plan) {
            if ($plan['action'] === 'refused') {
                continue;
            }

            foreach ($plan['devices'] as $deviceId) {
                $rows[$deviceId][] = ['table' => $table, 'pk' => $plan['pk']];
            }
        }

        return $rows;
    }
}
