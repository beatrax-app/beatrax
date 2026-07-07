<?php

declare(strict_types=1);

namespace Modules\Reports\Public\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Scopes\UserScope;
use Modules\Reports\Models\SavedReport;
use Modules\Reports\Public\Dto\ReportDefinition;
use Modules\Sync\Public\Events\SavedReportMutated;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Updates an existing saved_reports row's name/definition (Req 9).
 *
 * The user-scoped lookup happens BEFORE any transaction opens (mirrors
 * `EnvelopeWriter::setOverspendMode()`'s validate-before-transaction idiom,
 * 999.6-PATTERNS.md) via an explicit `withoutGlobalScope(UserScope::class)
 * ->where('user_id', $user->id)` guard — a foreign or missing id throws
 * `NotFoundHttpException` (404, never 403 — T-999.6-17), and the caller's
 * `$user` is trusted over the ambient auth guard the global `UserScope`
 * would otherwise apply.
 *
 * Only genuinely-changed fields are written and emitted (LWW per-field
 * convergence, same convention as `EnvelopeWriter::setAssigned()`'s
 * no-op-on-unchanged-value branch): a no-op update short-circuits before
 * ever opening a transaction or dispatching an event.
 */
final class UpdateReport
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Dispatcher $events,
    ) {}

    public function update(User $user, int $reportId, ReportDefinition $definition, string $name): SavedReport
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

        $newDefinition = $definition->toArray();

        /** @var array<string, mixed> $dirty */
        $dirty = [];
        if ($existing->name !== $name) {
            $dirty['name'] = $name;
        }
        if ($existing->definition !== $newDefinition) {
            $dirty['definition'] = $newDefinition;
        }

        if ($dirty === []) {
            return $existing;
        }

        /** @var SavedReportMutated|null $event */
        $event = null;

        $report = $this->db->connection()->transaction(function () use ($existing, $dirty, $user, &$event): SavedReport {
            $existing->fill($dirty);
            $existing->save();

            $event = new SavedReportMutated(
                reportId: $existing->id,
                userId: $user->id,
                mutationType: 'edit',
                dirtyFields: $dirty,
            );

            return $existing;
        });

        if ($event !== null) {
            $this->events->dispatch($event);
        }

        return $report;
    }
}
