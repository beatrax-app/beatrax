<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Scopes\UserScope;
use Modules\Reports\Internal\Support\PinOrderCompactor;
use Modules\Reports\Models\SavedReport;
use Modules\Sync\Public\Events\SavedReportMutated;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class DeleteReport
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Dispatcher $events,
    ) {}

    public function delete(User $user, int $reportId): void
    {
        // A foreign or missing id 404s rather than 403s, so another user's
        // report existence never leaks through the error.
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

            // Deleting a pinned report must not leave a gap in the pin order;
            // each changed row gets its own event so every op-log stays in step.
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
