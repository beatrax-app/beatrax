<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Listeners;

use Illuminate\Contracts\Bus\Dispatcher;
use Modules\DriftAlerts\Public\Events\DriftAlertDismissedCancelled;
use RuntimeException;

/**
 * Subscribes to the DriftAlerts-side Public event that signals the
 * user has dismissed an alert as a real-world cancellation. The real
 * implementation lands in a later wave: the cancelled series falls
 * out of every active forecast projection for the user via the
 * injected bus.
 *
 * The constructor signature + handle() shape are the locked DI surface
 * — later waves swap only the handle() body in, so the upstream event
 * subscription wired in ForecastingServiceProvider stays valid.
 *
 * Cross-module: imports Modules\DriftAlerts\Public\Events only — never
 * Modules\DriftAlerts\Internal.
 */
final readonly class ProjectForecastOnDriftDismissed
{
    public function __construct(private Dispatcher $bus) {}

    public function handle(DriftAlertDismissedCancelled $event): void
    {
        throw new RuntimeException(sprintf(
            'ProjectForecastOnDriftDismissed::handle is a scaffold; a later wave excludes the cancelled series from active projections via %s for event %s.',
            $this->bus::class,
            $event::class,
        ));
    }
}
