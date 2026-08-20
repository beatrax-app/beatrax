<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Listeners;

use Illuminate\Contracts\Routing\UrlGenerator;
use Modules\Core\Public\Support\Lang;
use Modules\DriftAlerts\Public\Events\DriftAlertOpened;
use Modules\Notifications\Internal\Support\DeterministicKeyDeriver;
use Modules\Notifications\Internal\Support\NotificationCopyRenderer;
use Modules\Notifications\Internal\Support\NotificationDraft;
use Modules\Notifications\Internal\Support\NotificationWriter;
use Psr\Log\LoggerInterface;
use Throwable;

final class PersistDriftAlert
{
    public function __construct(
        private readonly NotificationWriter $writer,
        private readonly UrlGenerator $urls,
        private readonly LoggerInterface $log,
        private readonly NotificationCopyRenderer $copyRenderer,
    ) {}

    public function handle(DriftAlertOpened $event): void
    {
        try {
            $direction = $event->direction === 'up' ? 'up' : 'down';
            $delta = number_format(abs($event->deltaMinor) / 100, 2);
            $currency = $event->currency;

            $draft = $this->copyRenderer->forUser($event->userId, fn (): NotificationDraft => new NotificationDraft(
                userId: $event->userId,
                triggerType: DeterministicKeyDeriver::TRIGGER_DRIFT_CHANGED,
                subjectKey: (string) $event->recurringSeriesId,
                occurrence: (string) $event->driftAlertId,
                title: Lang::get('notifications::copy.title.drift'),
                body: Lang::get('notifications::copy.body.drift', [
                    'direction' => Lang::get('notifications::copy.drift_direction.'.$direction),
                    'delta' => $delta,
                    'currency' => $currency,
                ]),
                params: ['target_kind' => 'series', 'target_id' => $event->recurringSeriesId],
                deepLinkRoute: $this->urls->route('drift.index'),
            ));
            $this->writer->write($draft);
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
