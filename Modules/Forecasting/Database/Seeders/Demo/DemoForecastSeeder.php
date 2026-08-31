<?php

declare(strict_types=1);

namespace Modules\Forecasting\Database\Seeders\Demo;

use Carbon\CarbonImmutable;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\DemoNames;
use Modules\Core\Public\Support\Lang;
use Modules\Forecasting\Internal\Pipeline\ProjectionPipeline;
use Modules\Forecasting\Models\ForecastScenario;
use Modules\Forecasting\Models\ForecastScenarioMutation;
use Modules\Forecasting\Models\ForecastShortfallWindow;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\AddOneOffPayload;
use Modules\Forecasting\Public\Enums\ForecastHorizon;
use Modules\Forecasting\Public\Enums\ScenarioMutationKind;
use Modules\Forecasting\Public\Services\ForecastHighlightsQuery;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\ValueObjects\Money;

final class DemoForecastSeeder
{
    public function __construct(
        private readonly ProjectionPipeline $pipeline,
        private readonly Clock $clock,
    ) {}

    /**
     * @var list<array{nameKey: string, descriptionKey: string}>
     */
    private const SCENARIOS = [
        [
            'nameKey' => 'forecast_base_case',
            'descriptionKey' => 'forecast_base_case_description',
        ],
        [
            'nameKey' => 'forecast_summer_holiday',
            'descriptionKey' => 'forecast_summer_holiday_description',
        ],
    ];

    private const WHAT_IF_NOTE_KEY = 'forecast_summer_holiday_charge';

    private const int WHAT_IF_AMOUNT_MINOR = -50000;

    /**
     * @param  array<string, User>  $users
     */
    public function run(array $users): int
    {
        $today = $this->clock->now()->startOfDay();

        $primary = $users['demo-1'] ?? null;
        if ($primary !== null) {
            $this->upsertScenario($primary, self::SCENARIOS[0]);
            $whatIf = $this->upsertScenario($primary, self::SCENARIOS[1]);

            $this->upsertWhatIfMutation($primary, $whatIf, $today);
        }

        foreach ($users as $user) {
            $this->projectAllHorizons($user);
        }

        // After the projection, never before it: the detector delete-then-writes
        // every (user, account, horizon, scenario) tuple it runs, so a window
        // seeded first is wiped by the run that follows.
        if ($primary !== null) {
            $this->upsertBaseCaseShortfall($primary, $today);
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

        foreach (ForecastHorizon::days() as $horizonDays) {
            $this->pipeline->project($user, null, $horizonDays);

            foreach ($scenarioIds as $scenarioId) {
                $this->pipeline->project($user, (int) $scenarioId, $horizonDays);
            }
        }
    }

    /**
     * @param  array{nameKey: string, descriptionKey: string}  $row
     */
    private function upsertScenario(User $user, array $row): ForecastScenario
    {
        // Every locale's rendering, not just today's: the row is found by a
        // translated name, so a re-seed under another APP_LOCALE duplicated it.
        $existing = ForecastScenario::query()
            ->where('user_id', $user->id)
            ->whereIn('name', DemoNames::everyRendering($row['nameKey']))
            ->first();

        // Left as first seeded, the way every peer demo seeder leaves its own
        // rows: a scenario carries a rename action, and re-seeding is not the
        // reader asking for the name they chose to be written over.
        if ($existing !== null) {
            return $existing;
        }

        $scenario = new ForecastScenario;
        $scenario->user_id = $user->id;
        $scenario->name = Lang::get('core::demo.'.$row['nameKey']);
        // The sentence names the size of the charge the mutation below
        // writes, so both read the one constant — a description naming an
        // amount the scenario does not carry is worse than naming none.
        $scenario->description = Lang::get('core::demo.'.$row['descriptionKey'], [
            'amount' => Money::ofMinor(abs(self::WHAT_IF_AMOUNT_MINOR), Currency::Eur->value)->format(),
        ]);
        $scenario->save();

        return $scenario;
    }

    private function upsertWhatIfMutation(User $user, ForecastScenario $whatIf, CarbonImmutable $today): void
    {
        $existing = ForecastScenarioMutation::query()
            ->where('user_id', $user->id)
            ->where('forecast_scenario_id', $whatIf->id)
            ->where('kind', ScenarioMutationKind::AddOneOff->value)
            ->exists();

        if ($existing) {
            return;
        }

        $payload = new AddOneOffPayload(
            date: $today->addDays(25)->toDateString(),
            amountMinor: self::WHAT_IF_AMOUNT_MINOR,
            currency: Currency::Eur->value,
            direction: 'expense',
            note: Lang::get('core::demo.'.self::WHAT_IF_NOTE_KEY),
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

    // scenario_id NULL, horizon 30: the sidebar badge and the chart's default
    // band both read the baseline run at the tile horizon and nothing else.
    private function upsertBaseCaseShortfall(User $user, CarbonImmutable $today): void
    {
        $asn = Account::query()
            ->where('user_id', $user->id)
            ->where('slug', 'asn-demo-1')
            ->first();

        if ($asn === null) {
            return;
        }

        $startsAt = $today->addDays(18);

        $existing = ForecastShortfallWindow::query()
            ->where('user_id', $user->id)
            ->where('account_id', $asn->id)
            ->whereNull('scenario_id')
            ->where('horizon_days', ForecastHighlightsQuery::TILE_HORIZON)
            ->whereDate('starts_at', $startsAt->toDateString())
            ->exists();

        if ($existing) {
            return;
        }

        ForecastShortfallWindow::query()->create([
            'user_id' => $user->id,
            'account_id' => $asn->id,
            'scenario_id' => null,
            'horizon_days' => ForecastHighlightsQuery::TILE_HORIZON,
            'starts_at' => $startsAt,
            'ends_at' => $today->addDays(22),
            'lowest_balance_minor' => -8500,
            'currency' => Currency::Eur->value,
            'buffer_used_minor' => 50000,
        ]);
    }
}
