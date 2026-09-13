<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Merge;

use Illuminate\Database\DatabaseManager;
use Modules\Sync\Internal\Config\CoveredTableOrder;
use Modules\Sync\Internal\OpLog\OpType;

// A peer's row landed; the ids it names did not. `translate()` rewrites a
// foreign key only where an alias says what the peer's number means here, and
// hands the number back where none does -- right for an id two devices compute
// alike, wrong for one only an alias can resolve, and silent for both.
/**
 * @link ../../../../.docs/features/sync/architecture.md#an-id-that-crossed-before-its-alias-existed
 */
final class UntranslatedParentIds
{
    // Keyed by parent table, device and the peer's number: one judgement serves
    // every row naming it. Forty-two transactions named twenty counterparties.
    /** @var array<string, ParentIdJudgment> */
    private array $judged = [];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly PeerRowAliases $aliases,
        private readonly PeerAuthoredRows $rows,
        private readonly CoveredTableOrder $tableOrder,
    ) {}

    // Every parent id a peer sent that this device applied, and the ones the
    // log proves are not the row the peer meant. `checked` is the denominator:
    // a pass that reports no finding and a pass that examined nothing read the
    // same until one of them said how many ids it looked at.
    /**
     * @return array{checked: int, agreed: int, findings: list<UntranslatedParentId>}
     */
    public function census(int $userId): array
    {
        $this->judged = [];
        $checked = 0;
        $agreed = 0;
        $findings = [];

        foreach ($this->rows->devices($userId) as $deviceId) {
            foreach ($this->rows->tables($deviceId, $userId) as $table) {
                $columns = $this->parentColumnsOf($table);

                foreach ($columns === [] ? [] : $this->rows->pks($table, $deviceId, $userId) as $pk) {
                    $row = $this->examine($table, $columns, $deviceId, $pk, $userId);
                    $checked += $row['checked'];
                    $agreed += $row['agreed'];
                    $findings = [...$findings, ...$row['findings']];
                }
            }
        }

        return ['checked' => $checked, 'agreed' => $agreed, 'findings' => $findings];
    }

    // One row the peer created and this device placed. A row that never landed
    // belongs to the other census -- `StrandedCreates` asks whether the row is
    // here, this one asks what the row it placed points at.
    /**
     * @param  array<string, string>  $columns
     * @return array{checked: int, agreed: int, findings: list<UntranslatedParentId>}
     */
    private function examine(string $table, array $columns, string $deviceId, string $pk, int $userId): array
    {
        $localId = $this->rows->landedId($table, $deviceId, $pk, $userId);

        if ($localId === null) {
            return ['checked' => 0, 'agreed' => 0, 'findings' => []];
        }

        $payload = $this->rows->payload($table, $pk, $deviceId, $userId);
        $stored = $this->rows->storedRow($table, $localId);
        $checked = 0;
        $agreed = 0;
        $findings = [];

        foreach ($columns as $column => $parentTable) {
            $peerValue = self::asText($payload[$column] ?? null);

            if ($peerValue === null) {
                continue;
            }

            $checked++;
            $at = new PeerParentColumn($table, $localId, $column, $parentTable, $deviceId, $pk, $peerValue);
            $found = $this->finding($at, self::asText($stored[$column] ?? null), $this->judge($payload, $at, $userId));

            $found === null ? $agreed++ : $findings[] = $found;
        }

        return ['checked' => $checked, 'agreed' => $agreed, 'findings' => $findings];
    }

    // Null where the judgement asks for nothing: no verdict at all, or a target
    // the column already holds -- this device having caught up, not a row to
    // move again.
    private function finding(PeerParentColumn $at, ?string $storedValue, ParentIdJudgment $judged): ?UntranslatedParentId
    {
        if ($judged->verdict === null) {
            return null;
        }

        $found = new UntranslatedParentId($at, $storedValue, $judged->correct, $judged->verdict, $judged->evidence);

        return $found->stillOwed() ? $found : null;
    }

    // What one of the peer's numbers means here, in the order the answers are
    // worth having: this device having independently written the same number
    // into the same column of the same row, then the alias the applier should
    // have recorded, then the peer's own create for the row the number names.
    /**
     * @param  array<string, mixed>  $payload
     */
    private function judge(array $payload, PeerParentColumn $at, int $userId): ParentIdJudgment
    {
        if ($this->corroborated($payload, $at, $userId)) {
            return ParentIdJudgment::agreed('this device wrote the same id into the same column of the same row');
        }

        return $this->judged[$at->parentTable."\0".$at->deviceId."\0".$at->peerValue]
            ??= $this->judgeParent($at->parentTable, $at->deviceId, $at->peerValue, $userId);
    }

    // The judgement that depends on the parent id alone, so it is asked once
    // per id however many rows name it.
    private function judgeParent(string $parentTable, string $deviceId, string $peerValue, int $userId): ParentIdJudgment
    {
        $alias = $this->aliases->localFor($parentTable, $deviceId, $peerValue, $userId);

        if ($alias !== null) {
            return ParentIdJudgment::misfiled($alias, sprintf('the alias recorded for %s %s from this peer', $parentTable, $peerValue));
        }

        $peerParent = $this->rows->payload($parentTable, $peerValue, $deviceId, $userId);

        return $peerParent === []
            ? $this->withoutACreate($parentTable, $peerValue, $userId)
            : $this->againstTheCreate($parentTable, $peerValue, $peerParent);
    }

    // The peer never announced the row its number names. A row this reader does
    // not own is outside every device's capture -- the seeded category taxonomy
    // is the case -- so its numbers are shared by construction rather than
    // translated. Anything else is a number nothing here can speak for.
    private function withoutACreate(string $parentTable, string $peerValue, int $userId): ParentIdJudgment
    {
        if (! $this->rows->ownedHere($parentTable, $peerValue, $userId)) {
            return ParentIdJudgment::agreed(sprintf('%s %s belongs to no reader, so no device captures it and its ids are shared', $parentTable, $peerValue));
        }

        return ParentIdJudgment::unspoken($this->whyNothingSpeaks($parentTable, $peerValue, $userId));
    }

    // Two silences, and the reader has to tell them apart. An id THIS device
    // announced is one the peer most likely wears unchanged -- but whether it
    // re-homed the create is written in the peer's alias table and in no other
    // place, so this side can report the reading and never assert it.
    private function whyNothingSpeaks(string $parentTable, string $peerValue, int $userId): string
    {
        $announcedHere = $this->db->connection()->table('op_log_entries')
            ->where('user_id', $userId)
            ->where('table_name', $parentTable)
            ->where('pk', $peerValue)
            ->where('op_type', OpType::CreateRow->value)
            ->whereNotIn('device_id', $this->rows->devices($userId))
            ->exists();

        return $announcedHere
            ? sprintf('this device announced %s %s to the peer; whether the peer kept that id or re-homed it is written only in the peer\'s own aliases', $parentTable, $peerValue)
            : sprintf('the log holds no create for %s %s from any device', $parentTable, $peerValue);
    }

    // The peer's own create for the parent, matched against this device's rows
    // the way a re-home matches them. A payload nothing can be found by is
    // refused rather than guessed, and one nothing here holds is the create
    // `sync:repair-stranded-creates` has still to take again.

    // Takes no user: the match runs over a unique index, and every index usable
    // here carries user_id, read from the peer's own payload. The reader is
    // already named by the key, so passing one in would be a second answer.
    /**
     * @param  array<string, mixed>  $peerParent
     */
    private function againstTheCreate(string $parentTable, string $peerValue, array $peerParent): ParentIdJudgment
    {
        if (! $this->aliases->naturalKeyIdentifies($parentTable, $peerParent)) {
            return ParentIdJudgment::unspoken(sprintf('the peer\'s create for %s %s carries no natural key', $parentTable, $peerValue));
        }

        $twin = $this->aliases->localTwinOf($parentTable, $peerParent);
        $key = self::readable($this->aliases->naturalKeyOf($parentTable, $peerParent));

        if ($twin === null) {
            return ParentIdJudgment::unplaceable(sprintf('no %s row of this reader holds %s', $parentTable, $key));
        }

        return ParentIdJudgment::misfiled((string) $twin, sprintf('the peer\'s %s %s is %s here, by %s', $parentTable, $peerValue, $twin, $key));
    }

    // Two devices that wrote the same number into the same column of the same
    // row agree about what the number means, whatever no alias says.

    // The same pk is NOT the same row where the table mints its ids: two
    // autoincrements reach 14 independently, and the second device's fourteenth
    // transaction agreeing on a counterparty number is a coincidence, not a
    // corroboration. So the natural key decides, as it does for a re-home.
    /**
     * @param  array<string, mixed>  $payload
     */
    private function corroborated(array $payload, PeerParentColumn $at, int $userId): bool
    {
        $key = $this->aliases->naturalKeyOf($at->table, $payload);

        if ($key === null) {
            return false;
        }

        foreach ($this->rows->otherAuthorsOf($at, $userId) as $other) {
            if ($this->aliases->naturalKeyOf($at->table, $this->rows->payload($at->table, $at->peerPk, $other, $userId)) === $key) {
                return true;
            }
        }

        return false;
    }

    // Every column of $table naming a covered parent, minus the owner. A peer's
    // user_id never crosses as a number to translate: the applier re-seeds it
    // from the session it is writing for.
    /**
     * @return array<string, string>
     */
    private function parentColumnsOf(string $table): array
    {
        $columns = [];

        foreach ($this->tableOrder->parentColumns($table) as $column => $parentTable) {
            if ($parentTable !== 'users') {
                $columns[$column] = $parentTable;
            }
        }

        return $columns;
    }

    // A null, an empty string and a bool are not ids and answer nothing.
    private static function asText(mixed $value): ?string
    {
        return (is_string($value) || is_int($value)) && (string) $value !== '' ? (string) $value : null;
    }

    // `PeerRowAliases` joins a key's parts with a NUL, which a terminal draws
    // as nothing at all -- two columns' values running together as one word.
    private static function readable(?string $key): string
    {
        return $key === null ? 'no natural key' : str_replace("\0", ', ', $key);
    }
}
