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
 * @link ../../../../.docs/features/notifications/architecture.md
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
            // Swallow - a failed persist must never break the
            // originating shortfall-detection run.
            $this->log->error('PersistForecastShortfall: failed to persist forecast shortfall notification', [
                'exception' => $e->getMessage(),
                'accountId' => $event->accountId,
                'userId' => $event->userId,
            ]);
        }
    }
}
