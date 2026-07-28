<?php

declare(strict_types=1);

namespace Modules\Reports\Public\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Scopes\UserScope;
use Modules\Reports\Internal\Support\PinOrderCompactor;
use Modules\Reports\Models\SavedReport;
use Modules\Sync\Public\Events\SavedReportMutated;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @link ../../../../.docs/features/reports/architecture.md
 */
final class TogglePin
{
    use CoercesScalars;

    private const MAX_PINS = 3;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Dispatcher $events,
    ) {}

    public function toggle(User $user, int $reportId): SavedReport
    {
        // Cross-user safety: user-scoped lookup before the write. A
        // foreign/missing id throws NotFoundHttpException (404, never 403).
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

                // Unpinning compacts the remaining pinned reports' pin_order
                // back to a dense 1..N sequence; each changed row gets its
                // own SavedReportMutated 'edit' to keep every device's Sync
                // op-log in step.
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

            // TOCTOU guard: the cap check runs inside this write
            // transaction, not before it opens — two concurrent toggle()
            // calls reading the count pre-transaction could both pass and
            // together pin a 4th report; the blocked one re-reads here.
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
}
