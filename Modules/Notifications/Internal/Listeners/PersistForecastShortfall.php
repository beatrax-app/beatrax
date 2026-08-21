<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Listeners;

use Illuminate\Contracts\Routing\UrlGenerator;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Forecasting\Public\Events\ForecastShortfallDetected;
use Modules\Notifications\Internal\Support\CopyLine;
use Modules\Notifications\Internal\Support\DeterministicKeyDeriver;
use Modules\Notifications\Internal\Support\NotificationCopyRenderer;
use Modules\Notifications\Internal\Support\NotificationCopySpec;
use Modules\Notifications\Internal\Support\NotificationDraft;
use Modules\Notifications\Internal\Support\NotificationWriter;
use Psr\Log\LoggerInterface;
use Throwable;

final class PersistForecastShortfall
{
    public function __construct(
        private readonly NotificationWriter $writer,
        private readonly UrlGenerator $urls,
        private readonly LoggerInterface $log,
        private readonly NotificationCopyRenderer $copyRenderer,
    ) {}

    public function handle(ForecastShortfallDetected $event): void
    {
        try {
            $copy = NotificationCopySpec::of(
                CopyLine::of('notifications::copy.title.forecast'),
                CopyLine::of('notifications::copy.body.forecast'),
            );

            $draft = $this->copyRenderer->forUser($event->userId, fn (): NotificationDraft => NotificationDraft::fromCopy(
                userId: $event->userId,
                triggerType: DeterministicKeyDeriver::TRIGGER_FORECAST_SHORTFALL,
                subjectKey: 'forecast',
                occurrence: $event->startsAt->toDateString(),
                copy: $copy,
                params: ['target_kind' => 'forecast'],
                deepLinkRoute: $this->urls->route('forecast.index'),
            ));
            $this->writer->write($draft);
        } catch (Throwable $e) {
            // Swallow - a failed persist must never break the
            // originating shortfall-detection run.
            $this->log->error('PersistForecastShortfall: failed to persist forecast shortfall notification', [
                ...SafeExceptionContext::describe($e),
                'accountId' => $event->accountId,
                'userId' => $event->userId,
            ]);
        }
    }
}
