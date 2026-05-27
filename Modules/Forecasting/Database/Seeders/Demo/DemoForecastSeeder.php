<?php

declare(strict_types=1);

namespace Modules\Forecasting\Database\Seeders\Demo;

use Modules\Core\Models\User;
use Modules\Forecasting\Models\ForecastScenario;

/**
 * Materialises one baseline `forecast_scenarios` row for the primary
 * demo user — a single "Base Case" scenario the cash-flow chart on
 * the dashboard hangs off so the README screenshot of the forecasting
 * surface renders with a non-empty scenario picker.
 *
 * Idempotency: the `(user_id, name)` UNIQUE on `forecast_scenarios`
 * guarantees a second seed run reuses the existing row;
 * `updateOrCreate` keyed on the same tuple keeps the primary key
 * stable across runs.
 *
 * The 60-day projection window is encoded in the production
 * ProjectForecastJob's `HORIZON_DAYS` constant (30 / 60 / 90); the
 * demo scenario inherits the same horizons through the daily forecast
 * sweep — no per-scenario horizon column exists on the schema, so the
 * seeder only ships the scenario container. Mutations + projected runs
 * are produced by the production forecasting pipeline when it next
 * fires, not pre-baked here.
 */
final class DemoForecastSeeder
{
    /**
     * Single baseline scenario for the primary demo user.
     *
     * @var array{name: string, description: string}
     */
    private const BASE_CASE = [
        'name' => 'Base Case',
        'description' => 'Baseline 60-day projection — no manual mutations, mirrors the live ledger forward.',
    ];

    /**
     * @param  array<string, User>  $users
     */
    public function run(array $users): int
    {
        $primary = $users['demo-1@beatrax.local'] ?? null;
        if ($primary !== null) {
            ForecastScenario::query()->updateOrCreate(
                [
                    'user_id' => $primary->id,
                    'name' => self::BASE_CASE['name'],
                ],
                [
                    'description' => self::BASE_CASE['description'],
                ],
            );
        }

        return ForecastScenario::query()
            ->whereIn('user_id', array_map(static fn (User $u): int => $u->id, $users))
            ->count();
    }
}
