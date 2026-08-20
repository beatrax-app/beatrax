<?php

declare(strict_types=1);

namespace Modules\Onboarding\Database\Seeders\Demo;

use Carbon\CarbonImmutable;
use Modules\Core\Models\User;
use Modules\Onboarding\Models\WizardProgress;
use Modules\Onboarding\Public\Enums\WizardStepStatus;

final class DemoWizardProgressSeeder
{
    /**
     * @var list<array{stepKey: string, status: string, completedAgeHours: ?int, data: ?array<string, mixed>}>
     */
    private const STEPS = [
        ['stepKey' => 'welcome', 'status' => WizardStepStatus::Done->value, 'completedAgeHours' => 96, 'data' => null],
        [
            'stepKey' => 'connect-bank',
            'status' => WizardStepStatus::Done->value,
            'completedAgeHours' => 72,
            'data' => ['connector' => 'asn-csv', 'filename' => 'asn-2026-05.csv'],
        ],
        [
            'stepKey' => 'connect-paypal',
            'status' => WizardStepStatus::InProgress->value,
            'completedAgeHours' => null,
            'data' => ['oauth_attempt' => 1],
        ],
        ['stepKey' => 'connect-card', 'status' => WizardStepStatus::Pending->value, 'completedAgeHours' => null, 'data' => null],
        [
            'stepKey' => 'connect-email',
            'status' => WizardStepStatus::Skipped->value,
            'completedAgeHours' => 48,
            'data' => ['reason' => 'user_opted_out'],
        ],
        ['stepKey' => 'first-import', 'status' => WizardStepStatus::Pending->value, 'completedAgeHours' => null, 'data' => null],
        ['stepKey' => 'done', 'status' => WizardStepStatus::Pending->value, 'completedAgeHours' => null, 'data' => null],
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
