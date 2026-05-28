<?php

declare(strict_types=1);

namespace Modules\Onboarding\Database\Seeders\Demo;

use Carbon\CarbonImmutable;
use Modules\Core\Models\User;
use Modules\Onboarding\Models\WizardProgress;

/**
 * Materialises a mixed-state `wizard_progress` slate for the primary
 * demo user so the ResumeStepResolver and the progress-dots renderer
 * both exercise every branch on a fresh demo install:
 *
 *   - `welcome` — done (the user clicked through the intro)
 *   - `connect-bank` — done (the user uploaded their first CAMT.053)
 *   - `connect-paypal` — in_progress (mid-OAuth flow)
 *   - `connect-card` — pending (not yet started)
 *   - `connect-email` — skipped (user opted out of inbox scanning)
 *   - `first-import` — pending (waiting on the connector steps)
 *   - `done` — pending (terminal step, not yet reached)
 *
 * Total: 2 done + 1 in_progress + 1 skipped + 3 pending = 7 rows
 * matching the canonical step set in WizardStepRegistry.
 *
 * Idempotency: the `(user_id, step_key)` UNIQUE on `wizard_progress`
 * keys the upsert via `updateOrCreate`. The seeder is intentionally
 * tolerant of the existing WizardProgressInitializer producing the
 * same row set first — both paths converge on the same final state
 * via `updateOrCreate`.
 *
 * The seeder hard-codes the 7 step keys rather than importing the
 * `WizardStepRegistry` from `Modules\Onboarding\Internal` so the
 * cross-module access rules stay clean. The step list is a schema-
 * level invariant: any change to the registry needs the demo seeder
 * extended in lockstep.
 */
final class DemoWizardProgressSeeder
{
    /**
     * Mixed-state per-step demo slate. Order mirrors the production
     * `WizardStepRegistry::STEPS` constant; status assignments cover
     * every legal value the schema trigger pair allows.
     *
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
