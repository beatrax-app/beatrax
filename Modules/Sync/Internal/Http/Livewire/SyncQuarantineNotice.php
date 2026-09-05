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

// The refusals nothing takes again, on the screen a reader without a developer
// flag can open. The recoverable half is the sibling backlog notice's subject
// and is excluded here: a hold that clears itself must not be dressed in copy
// that asks the reader to go and repair something.
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
        return $views->make('sync::livewire.sync-quarantine-notice', [
            'groups' => $currentUser->isAuthenticated()
                ? $this->groups($db, $currentUser->id(), $clock)
                : [],
        ]);
    }

    /**
     * @return list<array{outcome: QuarantineOutcome, tally: int, newest: ?string}>
     */
    private function groups(DatabaseManager $db, int $userId, Clock $clock): array
    {
        $tallies = [];
        $newest = [];

        foreach ($this->refusals($db, $userId) as $row) {
            $outcome = $this->outcomeOf($row['reason'] ?? null);

            if ($outcome === null) {
                continue;
            }

            $tallies[$outcome->value] = ($tallies[$outcome->value] ?? 0)
                + (is_numeric($row['tally'] ?? null) ? (int) $row['tally'] : 0);
            $newest[$outcome->value] = $this->later($newest[$outcome->value] ?? null, $row['newest'] ?? null);
        }

        return $this->present($tallies, $newest, $clock);
    }

    private function outcomeOf(mixed $reason): ?QuarantineOutcome
    {
        $case = is_string($reason) ? QuarantineReason::tryFrom($reason) : null;

        return $case === null ? null : QuarantineOutcome::of($case);
    }

    // Grouped in SQL: op_log_quarantine grows with every refusal a hostile or
    // merely out-of-date peer produces, and the reader needs one number per
    // outcome rather than the rows behind it.
    /**
     * @return list<array<string, mixed>>
     */
    private function refusals(DatabaseManager $db, int $userId): array
    {
        $rows = $db->connection()
            ->table('op_log_quarantine')
            ->where('user_id', $userId)
            ->whereIn('reason', QuarantineOutcome::terminalReasonValues())
            ->groupBy('reason')
            ->selectRaw('reason, COUNT(*) AS tally, MAX(created_at) AS newest')
            ->get();

        $refusals = [];

        foreach ($rows->all() as $row) {
            /** @var array<string, mixed> $fields */
            $fields = get_object_vars($row);
            $refusals[] = $fields;
        }

        return $refusals;
    }

    /**
     * @param  array<string, int>  $tallies
     * @param  array<string, ?string>  $newest
     * @return list<array{outcome: QuarantineOutcome, tally: int, newest: ?string}>
     */
    private function present(array $tallies, array $newest, Clock $clock): array
    {
        $now = $clock->now();
        $groups = [];

        foreach (QuarantineOutcome::cases() as $outcome) {
            $tally = $tallies[$outcome->value] ?? 0;

            if ($tally < 1) {
                continue;
            }

            $stamp = $newest[$outcome->value] ?? null;

            $groups[] = [
                'outcome' => $outcome,
                'tally' => $tally,
                'newest' => $stamp === null ? null : SyncStatusService::relativeTime($now, $stamp),
            ];
        }

        return $groups;
    }

    // The stamps are `Y-m-d H:i:s` strings written by one clock through
    // Clock::now()->toDateTimeString(), so the lexicographic comparison is the
    // chronological one and no parse is needed to pick the later of two.
    private function later(?string $held, mixed $arriving): ?string
    {
        if (! is_string($arriving) || $arriving === '') {
            return $held;
        }

        return $held === null || $arriving > $held ? $arriving : $held;
    }
}
