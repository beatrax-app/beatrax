<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher;
use Modules\Core\Models\User;
use Modules\Forecasting\Internal\Jobs\ProjectForecastJob;
use Modules\Forecasting\Public\Enums\ForecastHorizon;
use Modules\Forecasting\Public\Services\ScenarioQuery;

// Horizons come from ForecastHorizon::days() rather than a list written here:
// a later extension added two long horizons, and a hardcoded list would have
// silently stopped projecting them.
final class ProjectForecastsCommand extends Command
{
    /** @var string */
    protected $signature = 'forecasting:project';

    /** @var string */
    protected $description = 'Project every forecast horizon, baseline and saved scenario, for every user.';

    public function __construct(
        private readonly Dispatcher $bus,
        private readonly ScenarioQuery $scenarios,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        User::query()->lazyById(100)->each(function (User $user): void {
            foreach (ForecastHorizon::days() as $horizon) {
                $this->bus->dispatch(new ProjectForecastJob(
                    userId: $user->id,
                    scenarioId: null,
                    horizonDays: $horizon,
                ));
            }

            foreach ($this->scenarios->forUser($user) as $scenario) {
                foreach (ForecastHorizon::days() as $horizon) {
                    $this->bus->dispatch(new ProjectForecastJob(
                        userId: $user->id,
                        scenarioId: $scenario->id,
                        horizonDays: $horizon,
                    ));
                }
            }
        });

        return self::SUCCESS;
    }
}
