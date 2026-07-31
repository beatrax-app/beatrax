<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Listeners;

use Illuminate\Contracts\Routing\UrlGenerator;
use Modules\DriftAlerts\Public\Events\DriftAlertOpened;
use Modules\Notifications\Internal\Support\DeterministicKeyDeriver;
use Modules\Notifications\Internal\Support\NotificationDraft;
use Modules\Notifications\Internal\Support\NotificationWriter;
use Modules\Notifications\Public\NotificationCopy;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @link ../../../../.docs/features/notifications/architecture.md
 */
final class PersistDriftAlert
{
    public function __construct(
        private readonly NotificationWriter $writer,
        private readonly UrlGenerator $urls,
        private readonly LoggerInterface $log,
    ) {}

    public function handle(DriftAlertOpened $event): void
    {
        try {
            $direction = $event->direction === 'up' ? 'up' : 'down';
            $delta = number_format(abs($event->deltaMinor) / 100, 2);
            $currency = $event->currency;

            $this->writer->write(new NotificationDraft(
                userId: $event->userId,
                triggerType: DeterministicKeyDeriver::TRIGGER_DRIFT_CHANGED,
                subjectKey: (string) $event->recurringSeriesId,
                occurrence: (string) $event->driftAlertId,
                title: NotificationCopy::TITLE_DRIFT,
                body: 'A recurring charge moved '.$direction.' by '.$delta.' '.$currency.'.',
                params: ['target_kind' => 'series', 'target_id' => $event->recurringSeriesId],
                deepLinkRoute: $this->urls->route('drift.index'),
            ));
        } catch (Throwable $e) {
            // Swallow - a failed persist must never break the
            // originating drift-detection run.
            $this->log->error('PersistDriftAlert: failed to persist drift alert notification', [
                'exception' => $e->getMessage(),
                'driftAlertId' => $event->driftAlertId,
                'userId' => $event->userId,
            ]);
        }
    }
}
