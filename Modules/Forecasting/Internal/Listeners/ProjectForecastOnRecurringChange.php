<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Listeners;

use Illuminate\Contracts\Bus\Dispatcher;
use Modules\Recurring\Public\Events\RecurringSeriesApproved;
use Modules\Recurring\Public\Events\RecurringSeriesCadenceFlipped;
use Modules\Recurring\Public\Events\RecurringSeriesMetricsRefreshed;
use Modules\Recurring\Public\Events\RecurringSeriesRejected;
use RuntimeException;

/**
 * Subscribes to the four Recurring-side Public events that signal a
 * change in the recurring-series substrate (approve, cadence flip,
 * reject, metric refresh). The real implementation lands in a later
 * wave: each event fans out into one queued projection job per
 * (user, scenario, horizon) tuple via the injected bus.
 *
 * The constructor signature + handle() shape are the locked DI surface
 * — later waves swap only the handle() body in, so the upstream event
 * subscriptions wired in ForecastingServiceProvider stay valid.
 *
 * Cross-module: imports Modules\Recurring\Public\Events only — never
 * Modules\Recurring\Internal. The crossModuleAccessGoesThroughPublic
 * arch invariant enforces this contract.
 */
final readonly class ProjectForecastOnRecurringChange
{
    public function __construct(private Dispatcher $bus) {}

    public function handle(
        RecurringSeriesApproved|RecurringSeriesCadenceFlipped|RecurringSeriesRejected|RecurringSeriesMetricsRefreshed $event,
    ): void {
        throw new RuntimeException(sprintf(
            'ProjectForecastOnRecurringChange::handle is a scaffold; a later wave dispatches the per-(user, scenario, horizon) projection job via %s for event %s.',
            $this->bus::class,
            $event::class,
        ));
    }
}
