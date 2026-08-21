<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Listeners;

use Illuminate\Contracts\Routing\UrlGenerator;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\DriftAlerts\Public\Events\DriftAlertOpened;
use Modules\Notifications\Internal\Support\CopyLine;
use Modules\Notifications\Internal\Support\CopyParam;
use Modules\Notifications\Internal\Support\DeterministicKeyDeriver;
use Modules\Notifications\Internal\Support\NotificationCopyRenderer;
use Modules\Notifications\Internal\Support\NotificationCopySpec;
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

            $copy = NotificationCopySpec::of(
                CopyLine::of('notifications::copy.title.drift'),
                CopyLine::of('notifications::copy.body.drift', [
                    'direction' => CopyParam::line('notifications::copy.drift_direction.'.$direction),
                    // Absolute: the direction word already carries the sign,
                    // and "moved up by -12,50" is not a sentence.
                    'amount' => CopyParam::money(abs($event->deltaMinor), $event->currency),
                ]),
            );

            $draft = $this->copyRenderer->forUser($event->userId, fn (): NotificationDraft => NotificationDraft::fromCopy(
                userId: $event->userId,
                triggerType: DeterministicKeyDeriver::TRIGGER_DRIFT_CHANGED,
                subjectKey: (string) $event->recurringSeriesId,
                occurrence: (string) $event->driftAlertId,
                copy: $copy,
                params: ['target_kind' => 'series', 'target_id' => $event->recurringSeriesId],
                deepLinkRoute: $this->urls->route('drift.index'),
            ));
            $this->writer->write($draft);
        } catch (Throwable $e) {
            // Swallow - a failed persist must never break the
            // originating drift-detection run.
            $this->log->error('PersistDriftAlert: failed to persist drift alert notification', [
                ...SafeExceptionContext::describe($e),
                'driftAlertId' => $event->driftAlertId,
                'userId' => $event->userId,
            ]);
        }
    }
}
