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
 * @link ../../../../.docs/features/reports/architecture.md
 */
final class UpdateReport
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Dispatcher $events,
    ) {}

    public function update(User $user, int $reportId, ReportDefinition $definition, string $name): SavedReport
    {
        // Cross-user safety: user-scoped lookup before the write, resolved
        // before any transaction opens. A foreign/missing id throws
        // NotFoundHttpException (404, never 403); the caller's $user is
        // trusted over the ambient auth guard the global UserScope applies.
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

        // Only genuinely-changed fields are written and emitted; a no-op
        // update short-circuits before ever opening a transaction or
        // dispatching an event.
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

    // For dirty-comparison purposes only: sorts the list-valued filter
    // fields so a semantically-unchanged filter set compares equal
    // regardless of element order. The persisted $newDefinition above is
    // never passed through this method — only the two `!==` operands are.
    /**
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
