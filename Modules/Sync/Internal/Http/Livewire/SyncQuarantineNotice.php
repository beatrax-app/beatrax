<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Component;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Sync\Internal\OpLog\QuarantineOutcome;
use Modules\Sync\Internal\OpLog\QuarantineReason;
use Modules\Sync\Public\Services\SyncStatusService;

// Every refusal this device recorded, on the screen a reader without a
// developer flag can open. Terminal refusals get a block per outcome; the
// recoverable half gets one block of its own, in words that name a condition
// rather than an act, because what ends a hold is not something to press.
/**
 * @link ../../../../../.docs/features/sync/what-the-quarantine-tells-the-reader.md
 */
final class SyncQuarantineNotice extends Component
{
    public function render(
        ViewFactory $views,
        DatabaseManager $db,
        CurrentUser $currentUser,
        Clock $clock,
    ): View {
        $userId = $currentUser->isAuthenticated() ? $currentUser->id() : null;

        return $views->make('sync::livewire.sync-quarantine-notice', [
            'groups' => $userId === null ? [] : $this->groups($db, $userId, $clock),
            'held' => $userId === null ? null : $this->held($db, $userId, $clock),
        ]);
    }

    // One block per outcome rather than per reason: the four differ in what the
    // reader can do about them, and the reasons inside one do not.
    /**
     * @return list<array{outcome: QuarantineOutcome, tally: int, newest: ?string}>
     */
    private function groups(DatabaseManager $db, int $userId, Clock $clock): array
    {
        $groups = [];

        foreach (QuarantineOutcome::cases() as $outcome) {
            $counted = $this->records($db, $userId, array_map(
                static fn (QuarantineReason $reason): string => $reason->value,
                $outcome->reasons(),
            ));

            if ($counted['tally'] < 1) {
                continue;
            }

            $groups[] = [
                'outcome' => $outcome,
                'tally' => $counted['tally'],
                'newest' => $this->relative($counted['newest'], $clock),
            ];
        }

        return $groups;
    }

    // The recoverable half as ONE block, never one per reason: an absent parent
    // and a value this device could not read are the same fact to a reader —
    // the record is not here yet — and splitting them would spend four alerts
    // saying it. Null when nothing is held.
    /**
     * @return array{tally: int, newest: ?string}|null
     */
    private function held(DatabaseManager $db, int $userId, Clock $clock): ?array
    {
        $counted = $this->records($db, $userId, QuarantineReason::recoverable());

        return $counted['tally'] < 1 ? null : [
            'tally' => $counted['tally'],
            'newest' => $this->relative($counted['newest'], $clock),
        ];
    }

    // Counted in SQL and over DISTINCT rows, the way RefusedOperations counts
    // for the status line one screen up: a create is captured per FIELD, so one
    // refused transaction is one record missing here rather than eight, and a
    // line and a block reporting the same refusals must not disagree.
    /**
     * @param  list<string>  $reasons
     * @return array{tally: int, newest: ?string}
     */
    private function records(DatabaseManager $db, int $userId, array $reasons): array
    {
        if ($reasons === []) {
            return ['tally' => 0, 'newest' => null];
        }

        $row = $db->connection()
            ->table('op_log_quarantine')
            ->where('user_id', $userId)
            ->whereIn('reason', $reasons)
            ->selectRaw("COUNT(DISTINCT table_name || ':' || pk) AS tally, MAX(created_at) AS newest")
            ->first();

        $fields = $row === null ? [] : get_object_vars($row);

        return [
            'tally' => is_numeric($fields['tally'] ?? null) ? (int) $fields['tally'] : 0,
            'newest' => is_string($fields['newest'] ?? null) && $fields['newest'] !== '' ? $fields['newest'] : null,
        ];
    }

    private function relative(?string $stamp, Clock $clock): ?string
    {
        return $stamp === null ? null : SyncStatusService::relativeTime($clock->now(), $stamp);
    }
}
