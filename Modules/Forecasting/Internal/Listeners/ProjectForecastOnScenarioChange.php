<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Listeners;

use Illuminate\Contracts\Bus\Dispatcher;
use RuntimeException;

/**
 * Subscribes to the Forecasting-internal scenario lifecycle events
 * (created / mutated / deleted) that signal a saved scenario needs
 * re-projection. The real implementation lands in a later wave: each
 * event fans out into one queued projection job per (user, scenario,
 * horizon) tuple via the injected bus.
 *
 * The handle() parameter is typed `object` for now — a later wave
 * narrows it to the union of the three Forecasting Public scenario
 * events once those event classes land. Keeping the parameter
 * permissive lets ForecastingServiceProvider wire the subscription
 * once the event classes exist via a single $events->listen() line per
 * event with no signature breakage.
 */
final readonly class ProjectForecastOnScenarioChange
{
    public function __construct(private Dispatcher $bus) {}

    public function handle(object $event): void
    {
        throw new RuntimeException(sprintf(
            'ProjectForecastOnScenarioChange::handle is a scaffold; a later wave dispatches the per-(user, scenario, horizon) projection job via %s for event %s.',
            $this->bus::class,
            $event::class,
        ));
    }
}
