<?php

declare(strict_types=1);

namespace Modules\Reports\Public\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Modules\Core\Models\User;
use Modules\Core\Public\Scopes\UserScope;
use Modules\Reports\Models\SavedReport;
use Modules\Sync\Public\Events\SavedReportMutated;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Toggles a saved report's dashboard-pin state (Req 10, D-02).
 *
 * The 3-pin cap (UI-SPEC "You can pin up to 3 reports. Unpin one to add
 * this.") is enforced HERE, in the write-service layer — never trusted from
 * the Livewire UI (999.6-PATTERNS.md "TogglePin's 3-pin cap", T-999.6-21).
 * The cap check runs BEFORE any transaction opens (mirrors
 * `EnvelopeWriter::setOverspendMode()`'s validate-before-transaction idiom):
 * a 4th pin attempt throws `InvalidArgumentException` with the exact UI-SPEC
 * copy and never touches the database.
 *
 * Unpinning clears `pinned`/`pin_order` and compacts the remaining pinned
 * reports' `pin_order` values back to a dense 1..N sequence — each row whose
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

        if (! $existing->pinned) {
            $pinnedCount = $this->db->connection()
                ->table('saved_reports')
                ->where('user_id', $user->id)
                ->where('pinned', true)
                ->count();

            if ($pinnedCount >= self::MAX_PINS) {
                throw new InvalidArgumentException('You can pin up to 3 reports. Unpin one to add this.');
            }
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

                foreach ($this->compactPinOrders($user) as $compacted) {
                    $events[] = new SavedReportMutated(
                        reportId: $compacted['id'],
                        userId: $user->id,
                        mutationType: 'edit',
                        dirtyFields: ['pin_order' => $compacted['pin_order']],
                    );
                }

                return $existing;
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

    /**
     * Re-numbers the user's remaining pinned reports to a dense 1..N
     * `pin_order` sequence (ordered by their current `pin_order`) and
     * returns only the rows whose order actually changed.
     *
     * @return list<array{id: int, pin_order: int}>
     */
    private function compactPinOrders(User $user): array
    {
        $connection = $this->db->connection();

        $rows = $connection
            ->table('saved_reports')
            ->where('user_id', $user->id)
            ->where('pinned', true)
            ->orderBy('pin_order')
            ->get(['id', 'pin_order']);

        $changed = [];
        $order = 1;
        foreach ($rows as $row) {
            /** @var \stdClass $row */
            $id = self::toInt($row->id);
            $currentOrder = self::toInt($row->pin_order);
            if ($currentOrder !== $order) {
                $connection->table('saved_reports')->where('id', $id)->update(['pin_order' => $order]);
                $changed[] = ['id' => $id, 'pin_order' => $order];
            }
            $order++;
        }

        return $changed;
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
