<?php

declare(strict_types=1);

namespace Modules\Forecasting\Database\Seeders\Demo;

use Carbon\CarbonImmutable;
use Modules\Core\Models\User;
use Modules\Forecasting\Internal\Jobs\ProjectForecastJob;
use Modules\Forecasting\Internal\Pipeline\ProjectionPipeline;
use Modules\Forecasting\Models\ForecastScenario;
use Modules\Forecasting\Models\ForecastScenarioMutation;
use Modules\Forecasting\Models\ForecastShortfallWindow;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\AddOneOffPayload;
use Modules\Forecasting\Public\Enums\ScenarioMutationKind;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Enums\Currency;

final class DemoForecastSeeder
{
    public function __construct(
        private readonly ProjectionPipeline $pipeline,
    ) {}

    /**
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

        foreach ($users as $user) {
            $this->projectAllHorizons($user);
        }

        return ForecastScenario::query()
            ->whereIn('user_id', array_map(static fn (User $u): int => $u->id, $users))
            ->count();
    }

    // Seeded rows are written straight to the DB and fire no events, so nothing
    // dispatches ProjectForecastJob; without projecting here forecast_runs stays
    // empty and every forecast surface renders its computing sentinel.
    private function projectAllHorizons(User $user): void
    {
        $scenarioIds = ForecastScenario::query()
            ->where('user_id', $user->id)
            ->pluck('id')
            ->all();

        foreach (ProjectForecastJob::HORIZON_DAYS as $horizonDays) {
            $this->pipeline->project($user, null, $horizonDays);

            foreach ($scenarioIds as $scenarioId) {
                $this->pipeline->project($user, (int) $scenarioId, $horizonDays);
            }
        }
    }

    /**
     * @param  array{name: string, description: string}  $row
     */
    private function upsertScenario(User $user, array $row): ForecastScenario
    {
        return ForecastScenario::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'name' => $row['name'],
            ],
            [
                'description' => $row['description'],
            ],
        );
    }

    private function upsertWhatIfMutation(User $user, ForecastScenario $whatIf): void
    {
        $existing = ForecastScenarioMutation::query()
            ->where('user_id', $user->id)
            ->where('forecast_scenario_id', $whatIf->id)
            ->where('kind', ScenarioMutationKind::AddOneOff->value)
            ->exists();

        if ($existing) {
            return;
        }

        $today = CarbonImmutable::today();
        $payload = new AddOneOffPayload(
            date: $today->addDays(25)->toDateString(),
            amountMinor: -50000,
            currency: Currency::Eur->value,
            direction: 'expense',
            note: 'Hypothetical summer holiday charge',
        );

        // The cast checks `payload.kind() === row.kind` at write time, so `kind`
        // has to be assigned before `payload`.
        $mutation = new ForecastScenarioMutation;
        $mutation->user_id = $user->id;
        $mutation->forecast_scenario_id = $whatIf->id;
        $mutation->kind = ScenarioMutationKind::AddOneOff->value;
        $mutation->target_series_id = null;
        $mutation->payload = $payload;
        $mutation->save();
    }

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
            'currency' => Currency::Eur->value,
            'buffer_used_minor' => 50000,
        ]);
    }
}
