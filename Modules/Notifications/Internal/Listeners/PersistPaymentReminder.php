<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Listeners;

use Illuminate\Contracts\Routing\UrlGenerator;
use Modules\Core\Public\Support\Lang;
use Modules\Notifications\Internal\Support\DeterministicKeyDeriver;
use Modules\Notifications\Internal\Support\NotificationCopyRenderer;
use Modules\Notifications\Internal\Support\NotificationDraft;
use Modules\Notifications\Internal\Support\NotificationWriter;
use Modules\Recurring\Public\Events\PaymentReminderDue;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @link ../../../../.docs/features/notifications/architecture.md
 */
final class PersistPaymentReminder
{
    public function __construct(
        private readonly NotificationWriter $writer,
        private readonly UrlGenerator $urls,
        private readonly LoggerInterface $log,
        private readonly NotificationCopyRenderer $copyRenderer,
    ) {}

    public function handle(PaymentReminderDue $event): void
    {
        try {
            $dayLabel = $event->dueDate->dayName;
            $amountText = $event->expectedAmount->format(
                $event->expectedAmount->currency() === 'EUR' ? 'nl_NL' : 'en_US',
            );

            $draft = $this->copyRenderer->forUser($event->userId, fn (): NotificationDraft => new NotificationDraft(
                userId: $event->userId,
                triggerType: DeterministicKeyDeriver::TRIGGER_PAYMENT_REMINDER,
                subjectKey: (string) $event->seriesId,
                // The occurrence key is the due date (a date string,
                // never a datetime) - two devices computing at different
                // times of day must still derive the same id.
                occurrence: $event->dueDate->toDateString(),
                title: $event->confidenceLow
                    ? Lang::get('notifications::copy.title.payment_reminder_hedged', ['day' => $dayLabel])
                    : Lang::get('notifications::copy.title.payment_reminder_confident', ['day' => $dayLabel]),
                // Hedge the body too when confidence is low - "expected
                // around {day}" rather than a hard date.
                body: $event->confidenceLow
                    ? Lang::get('notifications::copy.body.payment_reminder_hedged', ['name' => $event->displayName, 'day' => $dayLabel, 'amount' => $amountText])
                    : Lang::get('notifications::copy.body.payment_reminder_confident', ['name' => $event->displayName, 'day' => $dayLabel, 'date' => $event->dueDate->format('d M'), 'amount' => $amountText]),
                params: ['target_kind' => 'series', 'target_id' => $event->seriesId],
                deepLinkRoute: $this->urls->route('recurring.series.show', ['seriesId' => $event->seriesId]),
            ));
            $this->writer->write($draft);
        } catch (Throwable $e) {
            // Swallow - a failed persist must never break the
            // originating reminder job run.
            $this->log->error('PersistPaymentReminder: failed to persist payment reminder', [
                'exception' => $e->getMessage(),
                'seriesId' => $event->seriesId,
                'userId' => $event->userId,
            ]);
        }
    }
}
