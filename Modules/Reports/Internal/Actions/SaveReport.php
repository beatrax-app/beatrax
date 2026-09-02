<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\DeviceMintedRowId;
use Modules\Reports\Internal\Dto\ReportDefinition;
use Modules\Reports\Models\SavedReport;
use Modules\Sync\Public\Events\SavedReportMutated;

final readonly class SaveReport
{
    public function __construct(
        private DatabaseManager $db,
        private Dispatcher $events,
    ) {}

    public function save(User $user, ReportDefinition $definition, string $name): SavedReport
    {
        /** @var SavedReportMutated|null $event */
        $event = null;

        $report = $this->db->connection()->transaction(function () use ($user, $definition, $name, &$event): SavedReport {
            $definitionArray = $definition->toArray();

            // Minted, not taken from the autoincrement: two devices used while
            // apart both take the next one, and saved_reports declares no
            // unique index to tell the two rows apart afterwards. Two reports
            // saved under one name are two reports, so it is not derived.
            /** @var SavedReport $created */
            $created = SavedReport::query()->forceCreate([
                'id' => DeviceMintedRowId::mint(),
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
