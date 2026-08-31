<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Scopes\UserScope;
use Modules\Reports\Internal\Dto\ReportDefinition;
use Modules\Reports\Models\SavedReport;
use Modules\Sync\Public\Events\SavedReportMutated;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class UpdateReport
{
    public function __construct(
        private DatabaseManager $db,
        private Dispatcher $events,
    ) {}

    public function update(User $user, int $reportId, ReportDefinition $definition, string $name): SavedReport
    {
        // A foreign or missing id 404s rather than 403s, and the caller's $user
        // is trusted over the ambient guard the global UserScope applies.
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

        // A no-op update short-circuits before opening a transaction or
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

    // Sorts the list-valued filter fields so a reordered but unchanged filter
    // set compares equal. Only the two `!==` operands go through here, never
    // the $newDefinition that gets persisted.
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
