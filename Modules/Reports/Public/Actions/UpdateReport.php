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
 *
 * WR-03: the dirty-check on `definition` compares a *normalized* copy of
 * both sides — the list-valued filter fields (`accounts`/`categories`/
 * `counterparties`) are sorted before the strict comparison — so a re-open
 * that re-serializes the same filter *set* in a different array order
 * (e.g. `[3,1,2]` vs. the stored `[1,2,3]`) is correctly treated as
 * unchanged. A raw strict comparison would false-positive on order alone
 * and write/emit a no-op edit, contradicting this class's own "no-op
 * short-circuits" contract.
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
        if (self::normalizeDefinition($existing->definition) !== self::normalizeDefinition($newDefinition)) {
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

    /**
     * Normalizes a report definition array for dirty-comparison purposes
     * only (WR-03): the list-valued filter fields are sorted so a
     * semantically-unchanged filter set compares equal regardless of
     * element order. The persisted value (`$newDefinition` above) is never
     * passed through this method — only the two sides of the `!==`
     * comparison are.
     *
     * @param  array<array-key, mixed>  $definition
     * @return array<array-key, mixed>
     */
    private static function normalizeDefinition(array $definition): array
    {
        foreach (['accounts', 'categories', 'counterparties'] as $key) {
            if (isset($definition[$key]) && is_array($definition[$key])) {
                sort($definition[$key]);
            }
        }

        return $definition;
    }
}
