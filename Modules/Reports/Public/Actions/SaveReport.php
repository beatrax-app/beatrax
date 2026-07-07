<?php

declare(strict_types=1);

namespace Modules\Reports\Public\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Reports\Models\SavedReport;
use Modules\Reports\Public\Dto\ReportDefinition;
use Modules\Sync\Public\Events\SavedReportMutated;

/**
 * Persists a new saved_reports row (Req 9). Mirrors
 * `EnvelopeWriter::setOverspendMode()`'s transaction-closes-before-event-
 * dispatch shape (999.6-PATTERNS.md, WR-06 / Pitfall 7): the Eloquent
 * write happens inside `$db->connection()->transaction()`, the
 * `SavedReportMutated` event is only dispatched AFTER the closure returns.
 *
 * `$definition->toArray()` is the full round-trip payload — reopening a
 * saved report via `ReportDefinition::from($row->definition)` reconstructs
 * every field losslessly, including `currencyMode` (SavedReportRoundTripTest).
 */
final class SaveReport
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Dispatcher $events,
    ) {}

    public function save(User $user, ReportDefinition $definition, string $name): SavedReport
    {
        /** @var SavedReportMutated|null $event */
        $event = null;

        $report = $this->db->connection()->transaction(function () use ($user, $definition, $name, &$event): SavedReport {
            $definitionArray = $definition->toArray();

            /** @var SavedReport $created */
            $created = SavedReport::query()->create([
                'user_id' => $user->id,
                'name' => $name,
                'definition' => $definitionArray,
                'pinned' => false,
            ]);

            $event = new SavedReportMutated(
                reportId: $created->id,
                userId: $user->id,
                mutationType: 'create',
                dirtyFields: [
                    'name' => $name,
                    'definition' => $definitionArray,
                    'pinned' => false,
                ],
            );

            return $created;
        });

        if ($event !== null) {
            $this->events->dispatch($event);
        }

        return $report;
    }
}
