<?php

declare(strict_types=1);

namespace Modules\Reports\Public\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Scopes\UserScope;
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

        /** @var SavedReportMutated|null $event */
        $event = null;

        $this->db->connection()->transaction(function () use ($existing, $user, &$event): void {
            $existing->delete();

            $event = new SavedReportMutated(
                reportId: $existing->id,
                userId: $user->id,
                mutationType: 'delete',
            );
        });

        if ($event !== null) {
            $this->events->dispatch($event);
        }
    }
}
