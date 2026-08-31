<?php

declare(strict_types=1);

namespace Modules\Onboarding\Database\Seeders\Demo;

use Carbon\CarbonImmutable;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Onboarding\Internal\Enums\WizardStepStatus;
use Modules\Onboarding\Internal\Services\WizardStepRegistry;
use Modules\Onboarding\Models\WizardProgress;

final class DemoWizardProgressSeeder
{
    // Only the steps whose state is the point of the demo. The registry names
    // the list; a step missing from here is seeded pending, which is what the
    // mount-time initializer would have written for it anyway.
    /**
     * @var array<string, array{status: string, completedAgeHours: ?int, data: ?array<string, mixed>}>
     */
    private const STATES = [
        'welcome' => ['status' => WizardStepStatus::Done->value, 'completedAgeHours' => 96, 'data' => null],
        'connect-bank' => [
            'status' => WizardStepStatus::Done->value,
            'completedAgeHours' => 72,
            'data' => ['connector' => 'asn-csv', 'filename' => 'asn-2026-05.csv'],
        ],
        'connect-paypal' => [
            'status' => WizardStepStatus::InProgress->value,
            'completedAgeHours' => null,
            'data' => ['oauth_attempt' => 1],
        ],
        'connect-email' => [
            'status' => WizardStepStatus::Skipped->value,
            'completedAgeHours' => 48,
            'data' => ['reason' => 'user_opted_out'],
        ],
    ];

    /** @var array{status: string, completedAgeHours: ?int, data: ?array<string, mixed>} */
    private const UNREACHED = ['status' => WizardStepStatus::Pending->value, 'completedAgeHours' => null, 'data' => null];

    public function __construct(
        private readonly WizardStepRegistry $registry,
        private readonly Clock $clock,
    ) {}

    /**
     * @param  array<string, User>  $users
     */
    public function run(array $users): int
    {
        $primary = $users['demo-1'] ?? null;
        if ($primary !== null) {
            $now = $this->clock->now();
            foreach ($this->registry->steps() as $stepKey) {
                $this->upsertStep($primary, $stepKey, self::STATES[$stepKey] ?? self::UNREACHED, $now);
            }
        }

        return WizardProgress::query()
            ->whereIn('user_id', array_map(static fn (User $u): int => $u->id, $users))
            ->count();
    }

    /**
     * @param  array{status: string, completedAgeHours: ?int, data: ?array<string, mixed>}  $row
     */
    private function upsertStep(User $user, string $stepKey, array $row, CarbonImmutable $now): void
    {
        $completedAt = $row['completedAgeHours'] === null
            ? null
            : $now->subHours($row['completedAgeHours']);

        WizardProgress::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'step_key' => $stepKey,
            ],
            [
                'status' => $row['status'],
                'data' => $row['data'],
                'completed_at' => $completedAt,
            ],
        );
    }
}
