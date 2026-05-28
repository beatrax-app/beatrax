<?php

declare(strict_types=1);

namespace Modules\Forecasting\Database\Seeders\Demo;

use Carbon\CarbonImmutable;
use Modules\Core\Models\User;
use Modules\Forecasting\Models\ForecastScenario;
use Modules\Forecasting\Models\ForecastScenarioMutation;
use Modules\Forecasting\Models\ForecastShortfallWindow;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\AddOneOffPayload;
use Modules\Ledger\Models\Account;

/**
 * Materialises forecast scenarios + one shortfall window for the
 * primary demo user so the /forecast surface renders with full
 * diversity:
 *
 *   - "Base Case" scenario — the baseline 60-day projection
 *     container. The dashboard's scenario picker reads this row as the
 *     default option.
 *   - "What-If" scenario — a second saved scenario carrying one
 *     `add_one_off` ScenarioMutation that models the user adding a
 *     hypothetical €500 holiday charge inside the projection horizon.
 *     Exercises the scenario-vs-baseline diff read on the /forecast
 *     page.
 *   - One `forecast_shortfall_windows` row against the Base Case so
 *     the dashboard's "Forecast highlights" shortfall banner has data
 *     to render against. The window models a brief dip below the
 *     account buffer between two large monthly outflows.
 *
 * Idempotency: the `(user_id, name)` UNIQUE on `forecast_scenarios`
 * keys the scenario upserts; the mutation row is upserted via the
 * application-keyed `(user_id, forecast_scenario_id, kind)` triple;
 * the shortfall window is upserted via the application-keyed
 * `(user_id, scenario_id, starts_at)` triple. Re-running the seeder
 * is a no-op for all three tables.
 *
 * The 60-day projection window is encoded in the production
 * ProjectForecastJob's `HORIZON_DAYS` constant (30 / 60 / 90); the
 * demo scenarios inherit the same horizons through the daily forecast
 * sweep — no per-scenario horizon column exists on the schema.
 */
final class DemoForecastSeeder
{
    /**
     * Demo scenarios. The first row is the baseline picker default;
     * the second row is the What-If model used to demonstrate the
     * scenario diff surface.
     *
     * @var list<array{name: string, description: string}>
     */
    private const SCENARIOS = [
        [
            'name' => 'Base Case',
            'description' => 'Baseline 60-day projection — no manual mutations, mirrors the live ledger forward.',
        ],
        [
            'name' => 'What-If: Summer holiday',
            'description' => 'What-if projection adding a one-off €500 holiday charge inside the 60-day horizon.',
        ],
    ];

    /**
     * @param  array<string, User>  $users
     */
    public function run(array $users): int
    {
        $primary = $users['demo-1@beatrax.local'] ?? null;
        if ($primary !== null) {
            $baseCase = $this->upsertScenario($primary, self::SCENARIOS[0]);
            $whatIf = $this->upsertScenario($primary, self::SCENARIOS[1]);

            $this->upsertWhatIfMutation($primary, $whatIf);
            $this->upsertBaseCaseShortfall($primary, $baseCase);
        }

        return ForecastScenario::query()
            ->whereIn('user_id', array_map(static fn (User $u): int => $u->id, $users))
            ->count();
    }

    /**
     * @param  array{name: string, description: string}  $row
     */
    private function upsertScenario(User $user, array $row): ForecastScenario
    {
        /** @var ForecastScenario $scenario */
        $scenario = ForecastScenario::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'name' => $row['name'],
            ],
            [
                'description' => $row['description'],
            ],
        );

        return $scenario;
    }

    /**
     * Idempotent What-If mutation insert. The cast enforces
     * `payload.kind() === row.kind` at write time so the assignment
     * order matters — `kind` must be set before `payload`.
     */
    private function upsertWhatIfMutation(User $user, ForecastScenario $whatIf): void
    {
        $existing = ForecastScenarioMutation::query()
            ->where('user_id', $user->id)
            ->where('forecast_scenario_id', $whatIf->id)
            ->where('kind', 'add_one_off')
            ->exists();

        if ($existing) {
            return;
        }

        $today = CarbonImmutable::today();
        $payload = new AddOneOffPayload(
            date: $today->addDays(25)->toDateString(),
            amountMinor: -50000,
            currency: 'EUR',
            direction: 'expense',
            note: 'Hypothetical summer holiday charge',
        );

        $mutation = new ForecastScenarioMutation;
        $mutation->user_id = $user->id;
        $mutation->forecast_scenario_id = $whatIf->id;
        $mutation->kind = 'add_one_off';
        $mutation->target_series_id = null;
        $mutation->payload = $payload;
        $mutation->save();
    }

    /**
     * Idempotent Base Case shortfall window insert. The window models
     * a brief dip below the account buffer 18-22 days into the
     * projection horizon, on the primary ASN account.
     */
    private function upsertBaseCaseShortfall(User $user, ForecastScenario $baseCase): void
    {
        $asn = Account::query()
            ->where('user_id', $user->id)
            ->where('slug', 'asn-demo-1')
            ->first();

        if ($asn === null) {
            return;
        }

        $today = CarbonImmutable::today();
        $startsAt = $today->addDays(18);

        $existing = ForecastShortfallWindow::query()
            ->where('user_id', $user->id)
            ->where('scenario_id', $baseCase->id)
            ->whereDate('starts_at', $startsAt->toDateString())
            ->exists();

        if ($existing) {
            return;
        }

        ForecastShortfallWindow::query()->create([
            'user_id' => $user->id,
            'account_id' => $asn->id,
            'scenario_id' => $baseCase->id,
            'starts_at' => $startsAt,
            'ends_at' => $today->addDays(22),
            'lowest_balance_minor' => -8500,
            'currency' => 'EUR',
            'buffer_used_minor' => 50000,
        ]);
    }
}
