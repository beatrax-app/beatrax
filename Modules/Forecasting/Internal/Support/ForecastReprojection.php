<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Support;

use Illuminate\Contracts\Bus\Dispatcher;
use Modules\Core\Models\User;
use Modules\Forecasting\Internal\Jobs\ProjectForecastJob;
use Modules\Forecasting\Public\Enums\ForecastHorizon;
use Modules\Forecasting\Public\Services\ScenarioQuery;

// Which projections a change invalidates. Four listeners answered it with the
// same loop over horizons and scenarios, and the copies were free to disagree
// about what a change reaches.
final readonly class ForecastReprojection
{
    public function __construct(
        private Dispatcher $bus,
        private ScenarioQuery $scenarios,
    ) {}

    public function baseline(int $userId): void
    {
        $this->project($userId, null);
    }

    public function scenario(int $userId, int $scenarioId): void
    {
        $this->project($userId, $scenarioId);
    }

    // A change to the series or to the drift behind them moves every line on
    // the page, so the baseline and every saved scenario are re-run. A reader
    // the row no longer belongs to leaves the scenarios alone.
    public function everything(int $userId): void
    {
        $this->baseline($userId);

        $user = User::query()->where('id', $userId)->first();

        if (! $user instanceof User) {
            return;
        }

        foreach ($this->scenarios->forUser($user) as $scenario) {
            $this->scenario($userId, $scenario->id);
        }
    }

    private function project(int $userId, ?int $scenarioId): void
    {
        foreach (ForecastHorizon::days() as $horizon) {
            $this->bus->dispatch(new ProjectForecastJob(
                userId: $userId,
                scenarioId: $scenarioId,
                horizonDays: $horizon,
            ));
        }
    }
}
