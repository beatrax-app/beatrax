<?php

declare(strict_types=1);

namespace Modules\Reports\Database\Seeders\Demo;

use Modules\Core\Models\User;
use Modules\Reports\Models\SavedReport;
use Modules\Reports\Public\Actions\SaveReport;
use Modules\Reports\Public\Actions\TogglePin;
use Modules\Reports\Public\Dto\ReportDefinition;

// Saved report definitions covering the questions the demo ledger can
// actually answer, two of them pinned so the dashboard shows its mini-card
// row. Definitions go through the public action, so what lands in the DB is
// exactly what the builder would have written.
final class DemoSavedReportsSeeder
{
    // Kept under the three-report pin cap the dashboard enforces, with the
    // unpinned entry proving the index renders both states.
    /** @var list<array{name: string, metric: string, dimension: string, period: string, viz: string, pinned: bool}> */
    private const REPORTS = [
        [
            'name' => 'Where the money went',
            'metric' => 'spend',
            'dimension' => 'category',
            'period' => 'last_3_months',
            'viz' => 'donut',
            'pinned' => true,
        ],
        [
            'name' => 'Monthly net position',
            'metric' => 'net',
            'dimension' => 'time_bucket',
            'period' => 'last_6_months',
            'viz' => 'bar',
            'pinned' => true,
        ],
        [
            'name' => 'Top counterparties this year',
            'metric' => 'spend',
            'dimension' => 'counterparty',
            'period' => 'ytd',
            'viz' => 'table',
            'pinned' => false,
        ],
    ];

    public function __construct(
        private readonly SaveReport $saveReport,
        private readonly TogglePin $togglePin,
    ) {}

    /**
     * @param  array<string, User>  $users
     */
    public function run(array $users): int
    {
        $primary = $users['demo-1@beatrax.local'] ?? null;
        if ($primary !== null) {
            foreach (self::REPORTS as $row) {
                $this->upsertReport($primary, $row);
            }
        }

        return SavedReport::query()
            ->whereIn('user_id', array_map(static fn (User $u): int => $u->id, $users))
            ->count();
    }

    /**
     * @param  array{name: string, metric: string, dimension: string, period: string, viz: string, pinned: bool}  $row
     */
    private function upsertReport(User $user, array $row): void
    {
        $existing = SavedReport::query()
            ->where('user_id', $user->id)
            ->where('name', $row['name'])
            ->exists();

        if ($existing) {
            return;
        }

        $report = $this->saveReport->save(
            $user,
            new ReportDefinition(
                metric: $row['metric'],
                dimension: $row['dimension'],
                periodPreset: $row['period'],
                granularity: 'monthly',
                currencyMode: 'base',
                viz: $row['viz'],
            ),
            $row['name'],
        );

        if ($row['pinned']) {
            $this->togglePin->toggle($user, $report->id);
        }
    }
}
