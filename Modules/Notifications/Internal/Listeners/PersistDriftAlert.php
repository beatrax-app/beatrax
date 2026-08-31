<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Listeners;

use Illuminate\Contracts\Routing\UrlGenerator;
use Modules\Core\Public\Navigation\Destination;
use Modules\Core\Public\Support\CopyLine;
use Modules\Core\Public\Support\CopyParam;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\DriftAlerts\Public\Events\DriftAlertOpened;
use Modules\Ledger\Public\Enums\Direction;
use Modules\Notifications\Internal\Support\NotificationCopyRenderer;
use Modules\Notifications\Internal\Support\NotificationCopySpec;
use Modules\Notifications\Internal\Support\NotificationDraft;
use Modules\Notifications\Internal\Support\NotificationWriter;
use Modules\Notifications\Public\Enums\NotificationTrigger;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class PersistDriftAlert
{
    public function __construct(
        private NotificationWriter $writer,
        private UrlGenerator $urls,
        private LoggerInterface $log,
        private NotificationCopyRenderer $copyRenderer,
    ) {}

    public function handle(DriftAlertOpened $event): void
    {
        try {
            $direction = self::movementWord($event->direction, $event->deltaMinor);

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
                triggerType: NotificationTrigger::DriftChanged,
                subjectKey: (string) $event->recurringSeriesId,
                occurrence: (string) $event->driftAlertId,
                copy: $copy,
                params: ['target_kind' => 'series', 'target_id' => $event->recurringSeriesId],
                deepLinkRoute: Destination::DriftAlerts->urlFrom($this->urls),
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

    // The event carries the SERIES direction — the drift_alerts.direction
    // vocabulary, 'expense' or 'income' — not which way the amount moved.
    // Expenses are stored negative, so a dearer bill is a MORE negative
    // delta; income moves with the sign of its delta.
    private static function movementWord(string $seriesDirection, int $deltaMinor): string
    {
        $movedUp = $seriesDirection === Direction::Expense->value
            ? $deltaMinor < 0
            : $deltaMinor > 0;

        return $movedUp ? 'up' : 'down';
    }
}
