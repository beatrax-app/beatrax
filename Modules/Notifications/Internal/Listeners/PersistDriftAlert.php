<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Listeners;

use Illuminate\Contracts\Routing\UrlGenerator;
use Modules\DriftAlerts\Public\Events\DriftAlertOpened;
use Modules\Notifications\Internal\Support\DeterministicKeyDeriver;
use Modules\Notifications\Internal\Support\NotificationWriter;
use Modules\Notifications\Public\NotificationCopy;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Persists Req 3's drift-alert reactive notification into the unified inbox.
 *
 * Subscribes to `Modules\DriftAlerts\Public\Events\DriftAlertOpened` — an
 * EXISTING event that already fires; registered by
 * `NotificationsServiceProvider`'s guarded listener table (18-05); this
 * class's exact name/namespace is what flips that guard on.
 *
 * The body copy is ported VERBATIM from
 * `Modules\Desktop\Internal\Listeners\DispatchOsNotification::handleDriftAlert()`
 * — the phrasing is locked, this phase persists and governs it, it does not
 * reword it (D-29).
 *
 * The occurrence key is the drift alert's OWN id (`$event->driftAlertId`),
 * not a date: `drift_alerts` already enforces one row per detected drift, so
 * keying on the alert's own id makes ONE alert produce ONE inbox row and
 * re-dispatching the SAME alert re-derive the SAME notification id —
 * `NotificationWriter`'s `insertOrIgnore` silently absorbs the duplicate.
 *
 * The whole handler body is wrapped in the never-throw envelope (D-07),
 * cloned from `SyncCaptureListener::handle()`: a failed notification-persist
 * must NEVER break the originating drift-detection run.
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

            $this->writer->write(
                userId: $event->userId,
                triggerType: DeterministicKeyDeriver::TRIGGER_DRIFT_CHANGED,
                subjectKey: (string) $event->recurringSeriesId,
                occurrence: (string) $event->driftAlertId,
                title: NotificationCopy::TITLE_DRIFT,
                body: 'A recurring charge moved '.$direction.' by '.$delta.' '.$currency.'.',
                params: ['target_kind' => 'series', 'target_id' => $event->recurringSeriesId],
                deepLinkRoute: $this->urls->route('drift.index'),
            );
        } catch (Throwable $e) {
            // Swallow — a failed persist must NEVER break the originating
            // drift-detection run (D-07).
            $this->log->error('PersistDriftAlert: failed to persist drift alert notification', [
                'exception' => $e->getMessage(),
                'driftAlertId' => $event->driftAlertId,
                'userId' => $event->userId,
            ]);
        }
    }
}
