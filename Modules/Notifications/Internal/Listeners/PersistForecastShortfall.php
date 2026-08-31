<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Listeners;

use Illuminate\Contracts\Routing\UrlGenerator;
use Modules\Core\Public\Navigation\Destination;
use Modules\Core\Public\Support\CopyLine;
use Modules\Core\Public\Support\CopyParam;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Forecasting\Public\Events\ForecastShortfallDetected;
use Modules\Notifications\Internal\Support\DeterministicKeyDeriver;
use Modules\Notifications\Internal\Support\NotificationCopyRenderer;
use Modules\Notifications\Internal\Support\NotificationCopySpec;
use Modules\Notifications\Internal\Support\NotificationDraft;
use Modules\Notifications\Internal\Support\NotificationWriter;
use Modules\Notifications\Public\Enums\NotificationTrigger;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class PersistForecastShortfall
{
    public function __construct(
        private NotificationWriter $writer,
        private UrlGenerator $urls,
        private LoggerInterface $log,
        private NotificationCopyRenderer $copyRenderer,
    ) {}

    // The zero-crossing floor the detector falls back to when the account
    // carries no minimum buffer of its own. Zero is what rides on the event.
    private const int ZERO_CROSSING_FLOOR = 0;

    // Two things the sentence stated that the detector does not: the floor is
    // the reader's own minimum buffer wherever they set one, and the window is
    // the run's own horizon — ProjectForecastsCommand walks every
    // ForecastHorizon case, so a dip a year out was called one inside thirty days.
    private static function bodyLine(ForecastShortfallDetected $event): CopyLine
    {
        $startsOn = CopyParam::shortDate($event->startsAt);

        if ($event->bufferUsedMinor === self::ZERO_CROSSING_FLOOR) {
            return CopyLine::of('notifications::copy.body.forecast', ['date' => $startsOn]);
        }

        return CopyLine::of('notifications::copy.body.forecast_buffer', [
            'buffer' => CopyParam::money($event->bufferUsedMinor, $event->currency),
            'date' => $startsOn,
        ]);
    }

    public function handle(ForecastShortfallDetected $event): void
    {
        // A scenario is a question, not a forecast. The inbox is one of the
        // reads it has to stay out of, so a what-if dip raises nothing.
        if ($event->scenarioId !== null) {
            return;
        }

        try {
            $copy = NotificationCopySpec::of(
                CopyLine::of('notifications::copy.title.forecast'),
                self::bodyLine($event),
            );

            $draft = $this->copyRenderer->forUser($event->userId, fn (): NotificationDraft => NotificationDraft::fromCopy(
                userId: $event->userId,
                triggerType: NotificationTrigger::ForecastShortfall,
                subjectKey: DeterministicKeyDeriver::forecastShortfallSubject($event->accountId),
                occurrence: $event->startsAt->toDateString(),
                copy: $copy,
                params: ['target_kind' => 'forecast'],
                deepLinkRoute: Destination::Forecasts->urlFrom($this->urls),
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
