<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Listeners;

use Illuminate\Contracts\Routing\UrlGenerator;
use Modules\Forecasting\Public\Events\ForecastShortfallDetected;
use Modules\Notifications\Internal\Support\DeterministicKeyDeriver;
use Modules\Notifications\Internal\Support\NotificationWriter;
use Modules\Notifications\Public\NotificationCopy;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Persists Req 3's forecast-shortfall reactive notification into the
 * unified inbox.
 *
 * Subscribes to `Modules\Forecasting\Public\Events\ForecastShortfallDetected`
 * — an EXISTING event that already fires; registered by
 * `NotificationsServiceProvider`'s guarded listener table (18-05); this
 * class's exact name/namespace is what flips that guard on.
 *
 * The body copy is ported VERBATIM from
 * `Modules\Desktop\Internal\Listeners\DispatchOsNotification::handleForecastShortfall()`
 * — the phrasing is locked, this phase persists and governs it, it does not
 * reword it (D-29).
 *
 * The occurrence key is the shortfall window's start date
 * (`$event->startsAt`), so one shortfall per date yields one row — the same
 * shortfall re-detected for the same start date re-derives the same
 * notification id, absorbed by `NotificationWriter`'s `insertOrIgnore`.
 *
 * The whole handler body is wrapped in the never-throw envelope (D-07),
 * cloned from `SyncCaptureListener::handle()`: a failed notification-persist
 * must NEVER break the originating shortfall-detection run.
 */
final class PersistForecastShortfall
{
    public function __construct(
        private readonly NotificationWriter $writer,
        private readonly UrlGenerator $urls,
        private readonly LoggerInterface $log,
    ) {}

    public function handle(ForecastShortfallDetected $event): void
    {
        try {
            $this->writer->write(
                userId: $event->userId,
                triggerType: DeterministicKeyDeriver::TRIGGER_FORECAST_SHORTFALL,
                subjectKey: 'forecast',
                occurrence: $event->startsAt->toDateString(),
                title: NotificationCopy::TITLE_FORECAST,
                body: 'Your projected balance dips below zero within the next 30 days.',
                params: ['target_kind' => 'forecast'],
                deepLinkRoute: $this->urls->route('forecast.index'),
            );
        } catch (Throwable $e) {
            // Swallow — a failed persist must NEVER break the originating
            // shortfall-detection run (D-07).
            $this->log->error('PersistForecastShortfall: failed to persist forecast shortfall notification', [
                'exception' => $e->getMessage(),
                'accountId' => $event->accountId,
                'userId' => $event->userId,
            ]);
        }
    }
}
