<?php

declare(strict_types=1);

namespace Modules\Reports\Public\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Reports\Models\SavedReport;
use Modules\Reports\Public\Dto\ReportDefinition;
use Modules\Sync\Public\Events\SavedReportMutated;

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
