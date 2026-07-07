<?php

declare(strict_types=1);

namespace Modules\Reports\Public\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Scopes\UserScope;
use Modules\Reports\Internal\Support\PinOrderCompactor;
use Modules\Reports\Models\SavedReport;
use Modules\Sync\Public\Events\SavedReportMutated;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Deletes a saved_reports row, including its pin state (Req 9/10).
 *
 * Mirrors `DeleteCategorizationRule`'s cross-user-safety shape: the
 * user-scoped lookup happens before the write, and a foreign/missing id
 * throws `NotFoundHttpException` (404, never 403 — T-999.6-17) rather than
 * silently no-op'ing, so the existence of another user's report is never
 * leaked through the error path either.
 *
 * WR-02: when the deleted report was pinned, the remaining pinned reports'
 * `pin_order` values are compacted back to a dense 1..N sequence inside the
 * same transaction, via the shared `PinOrderCompactor` (also used by
 * `TogglePin`'s unpin path) — deleting a pinned report must not leave a gap
 * in the pin order, mirroring the invariant `TogglePin` already maintains
 * on unpin. Each row whose `pin_order` actually changes gets its own
 * `SavedReportMutated` 'edit' so every device's Sync op-log stays in step.
 */
final class DeleteReport
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Dispatcher $events,
    ) {}

    public function delete(User $user, int $reportId): void
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

        $this->db->connection()->transaction(function () use ($existing, $user, &$events): void {
            $wasPinned = $existing->pinned;

            $existing->delete();

            $events[] = new SavedReportMutated(
                reportId: $existing->id,
                userId: $user->id,
                mutationType: 'delete',
            );

            if ($wasPinned) {
                foreach (PinOrderCompactor::compact($this->db->connection(), $user) as $compacted) {
                    $events[] = new SavedReportMutated(
                        reportId: $compacted['id'],
                        userId: $user->id,
                        mutationType: 'edit',
                        dirtyFields: ['pin_order' => $compacted['pin_order']],
                    );
                }
            }
        });

        foreach ($events as $event) {
            $this->events->dispatch($event);
        }
    }
}
