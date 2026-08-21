<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Scopes\UserScope;
use Modules\Core\Public\Support\Lang;
use Modules\Reports\Internal\Support\PinOrderCompactor;
use Modules\Reports\Models\SavedReport;
use Modules\Sync\Public\Events\SavedReportMutated;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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

                // Unpinning compacts the rest back to a dense 1..N; each changed
                // row gets its own event so every device's op-log stays in step.
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

            // The cap check runs inside the write transaction: read before it
            // opens, two concurrent toggles could both pass and pin a 4th.
            $pinnedCount = $this->db->connection()
                ->table('saved_reports')
                ->where('user_id', $user->id)
                ->where('pinned', true)
                ->count();

            if ($pinnedCount >= self::MAX_PINS) {
                throw new InvalidArgumentException(Lang::get('reports::index.pin_cap'));
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
