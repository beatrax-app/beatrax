<?php

declare(strict_types=1);

namespace Modules\Onboarding\Database\Seeders\Demo;

use Carbon\CarbonImmutable;
use Modules\Core\Models\User;
use Modules\Onboarding\Models\WizardProgress;

// Materialises a mixed-state wizard_progress slate (done/in_progress/
// skipped/pending) for the primary demo user. Hard-codes the step keys
// rather than importing WizardStepRegistry so cross-module access rules
// stay clean; upserts on (user_id, step_key).
final class DemoWizardProgressSeeder
{
    /**
     * @var list<array{stepKey: string, status: string, completedAgeHours: ?int, data: ?array<string, mixed>}>
     */
    private const STEPS = [
        ['stepKey' => 'welcome', 'status' => 'done', 'completedAgeHours' => 96, 'data' => null],
        [
            'stepKey' => 'connect-bank',
            'status' => 'done',
            'completedAgeHours' => 72,
            'data' => ['connector' => 'asn-csv', 'filename' => 'asn-2026-05.csv'],
        ],
        [
            'stepKey' => 'connect-paypal',
            'status' => 'in_progress',
            'completedAgeHours' => null,
            'data' => ['oauth_attempt' => 1],
        ],
        ['stepKey' => 'connect-card', 'status' => 'pending', 'completedAgeHours' => null, 'data' => null],
        [
            'stepKey' => 'connect-email',
            'status' => 'skipped',
            'completedAgeHours' => 48,
            'data' => ['reason' => 'user_opted_out'],
        ],
        ['stepKey' => 'first-import', 'status' => 'pending', 'completedAgeHours' => null, 'data' => null],
        ['stepKey' => 'done', 'status' => 'pending', 'completedAgeHours' => null, 'data' => null],
    ];

    /**
     * @param  array<string, User>  $users
     */
    public function run(array $users): int
    {
        $primary = $users['demo-1@beatrax.local'] ?? null;
        if ($primary !== null) {
            foreach (self::STEPS as $row) {
                $this->upsertStep($primary, $row);
            }
        }

        return WizardProgress::query()
            ->whereIn('user_id', array_map(static fn (User $u): int => $u->id, $users))
            ->count();
    }

    /**
     * @param  array{stepKey: string, status: string, completedAgeHours: ?int, data: ?array<string, mixed>}  $row
     */
    private function upsertStep(User $user, array $row): void
    {
        $completedAt = $row['completedAgeHours'] === null
            ? null
            : CarbonImmutable::now()->subHours($row['completedAgeHours']);

        WizardProgress::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'step_key' => $row['stepKey'],
            ],
            [
                'status' => $row['status'],
                'data' => $row['data'],
                'completed_at' => $completedAt,
            ],
        );
    }
}
