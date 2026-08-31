<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Listeners;

use Illuminate\Contracts\Routing\UrlGenerator;
use Modules\Core\Public\Support\CopyLine;
use Modules\Core\Public\Support\CopyParam;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Notifications\Internal\Support\NotificationCopyRenderer;
use Modules\Notifications\Internal\Support\NotificationCopySpec;
use Modules\Notifications\Internal\Support\NotificationDraft;
use Modules\Notifications\Internal\Support\NotificationWriter;
use Modules\Notifications\Public\Enums\NotificationTrigger;
use Modules\Recurring\Public\Events\PaymentReminderDue;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class PersistPaymentReminder
{
    public function __construct(
        private NotificationWriter $writer,
        private UrlGenerator $urls,
        private LoggerInterface $log,
        private NotificationCopyRenderer $copyRenderer,
    ) {}

    public function handle(PaymentReminderDue $event): void
    {
        try {
            $dayLabel = CopyParam::dayName($event->dueDate);
            // The date rides beside the weekday in every line: the lead may be
            // thirty days, four Tuesdays fit in that, and the row is read long
            // after it is written — by then the weekday it names can be past.
            $dateLabel = CopyParam::shortDate($event->dueDate);
            $amount = CopyParam::money($event->expectedAmount->toMinor(), $event->expectedAmount->currency());

            // Low confidence hedges BOTH halves: a firm title over a hedged
            // body reads as a promise the next sentence takes back.
            $copy = $event->confidenceLow
                ? NotificationCopySpec::of(
                    CopyLine::of('notifications::copy.title.payment_reminder_hedged', ['day' => $dayLabel, 'date' => $dateLabel]),
                    CopyLine::of('notifications::copy.body.payment_reminder_hedged', ['name' => $event->displayName, 'day' => $dayLabel, 'date' => $dateLabel, 'amount' => $amount]),
                )
                : NotificationCopySpec::of(
                    CopyLine::of('notifications::copy.title.payment_reminder_confident', ['day' => $dayLabel, 'date' => $dateLabel]),
                    CopyLine::of('notifications::copy.body.payment_reminder_confident', ['name' => $event->displayName, 'day' => $dayLabel, 'date' => $dateLabel, 'amount' => $amount]),
                );

            $draft = $this->copyRenderer->forUser($event->userId, fn (): NotificationDraft => NotificationDraft::fromCopy(
                userId: $event->userId,
                triggerType: NotificationTrigger::PaymentReminder,
                subjectKey: (string) $event->seriesId,
                // A date string, never a datetime: two devices computing at
                // different times of day must derive the same id.
                occurrence: $event->dueDate->toDateString(),
                copy: $copy,
                params: ['target_kind' => 'series', 'target_id' => $event->seriesId],
                deepLinkRoute: $this->urls->route('recurring.series.show', ['seriesId' => $event->seriesId]),
            ));
            $this->writer->write($draft);
        } catch (Throwable $e) {
            // Swallow - a failed persist must never break the
            // originating reminder job run.
            $this->log->error('PersistPaymentReminder: failed to persist payment reminder', [
                ...SafeExceptionContext::describe($e),
                'seriesId' => $event->seriesId,
                'userId' => $event->userId,
            ]);
        }
    }
}
