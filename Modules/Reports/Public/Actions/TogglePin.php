<?php

declare(strict_types=1);

namespace Modules\Reports\Public\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Modules\Core\Models\User;
use Modules\Core\Public\Scopes\UserScope;
use Modules\Reports\Internal\Support\PinOrderCompactor;
use Modules\Reports\Models\SavedReport;
use Modules\Sync\Public\Events\SavedReportMutated;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Toggles a saved report's dashboard-pin state (Req 10, D-02).
 *
 * The 3-pin cap (UI-SPEC "You can pin up to 3 reports. Unpin one to add
 * this.") is enforced HERE, in the write-service layer — never trusted from
 * the Livewire UI (999.6-PATTERNS.md "TogglePin's 3-pin cap", T-999.6-21).
 * WR-01: the cap check runs INSIDE the same DB transaction as the write, not
 * before it opens — two concurrent `toggle()` calls that both read the
 * pinned count before either commits would otherwise both pass a
 * pre-transaction check and together pin a 4th report (TOCTOU). Reading the
 * count inside the transaction means the second, blocked transaction
 * re-reads the now-updated count once it acquires the write lock and
 * correctly rejects the 4th pin — SQLite's single-writer serialization
 * actually protects the invariant. A 4th pin attempt throws
 * `InvalidArgumentException` with the exact UI-SPEC copy and the transaction
 * rolls back, so the database is never touched.
 *
 * Unpinning clears `pinned`/`pin_order` and compacts the remaining pinned
 * reports' `pin_order` values back to a dense 1..N sequence via the shared
 * `PinOrderCompactor` (WR-02, also used by `DeleteReport`) — each row whose
 * `pin_order` actually changes gets its own `SavedReportMutated` 'edit' so
 * every device's Sync op-log stays in step with the on-screen pin order.
 */
final class TogglePin
{
    private const MAX_PINS = 3;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Dispatcher $events,
    ) {}

    public function toggle(User $user, int $reportId): SavedReport
    {
        /** @var SavedReport|null $existing */
        $existing = SavedReport::query()
            ->withoutGlobalScope(UserScope::class)
            ->where('id', $reportId)
            ->where('user_id', $user->id)
            ->first();

        if (! $existing instanceof SavedReport) {
            throw new NotFoundHttpException('Report not found.');
        }

        /** @var list<SavedReportMutated> $events */
        $events = [];

        $report = $this->db->connection()->transaction(function () use ($existing, $user, &$events): SavedReport {
            if ($existing->pinned) {
                $existing->pinned = false;
                $existing->pin_order = null;
                $existing->save();

                $events[] = new SavedReportMutated(
                    reportId: $existing->id,
                    userId: $user->id,
                    mutationType: 'edit',
                    dirtyFields: ['pinned' => false, 'pin_order' => null],
                );

                foreach (PinOrderCompactor::compact($this->db->connection(), $user) as $compacted) {
                    $events[] = new SavedReportMutated(
                        reportId: $compacted['id'],
                        userId: $user->id,
                        mutationType: 'edit',
                        dirtyFields: ['pin_order' => $compacted['pin_order']],
                    );
                }

                return $existing;
            }

            // WR-01: the pinned-count cap check runs HERE, inside the write
            // transaction, right before the write itself — not as a
            // pre-transaction round trip. See class docblock for the TOCTOU
            // rationale.
            $pinnedCount = $this->db->connection()
                ->table('saved_reports')
                ->where('user_id', $user->id)
                ->where('pinned', true)
                ->count();

            if ($pinnedCount >= self::MAX_PINS) {
                throw new InvalidArgumentException('You can pin up to 3 reports. Unpin one to add this.');
            }

            $maxOrder = $this->db->connection()
                ->table('saved_reports')
                ->where('user_id', $user->id)
                ->where('pinned', true)
                ->max('pin_order');
            $nextOrder = self::toInt($maxOrder) + 1;

            $existing->pinned = true;
            $existing->pin_order = $nextOrder;
            $existing->save();

            $events[] = new SavedReportMutated(
                reportId: $existing->id,
                userId: $user->id,
                mutationType: 'edit',
                dirtyFields: ['pinned' => true, 'pin_order' => $nextOrder],
            );

            return $existing;
        });

        foreach ($events as $event) {
            $this->events->dispatch($event);
        }

        return $report;
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
