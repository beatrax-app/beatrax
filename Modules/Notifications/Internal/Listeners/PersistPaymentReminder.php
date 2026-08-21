<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Listeners;

use Illuminate\Contracts\Routing\UrlGenerator;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Notifications\Internal\Support\CopyLine;
use Modules\Notifications\Internal\Support\CopyParam;
use Modules\Notifications\Internal\Support\DeterministicKeyDeriver;
use Modules\Notifications\Internal\Support\NotificationCopyRenderer;
use Modules\Notifications\Internal\Support\NotificationCopySpec;
use Modules\Notifications\Internal\Support\NotificationDraft;
use Modules\Notifications\Internal\Support\NotificationWriter;
use Modules\Recurring\Public\Events\PaymentReminderDue;
use Psr\Log\LoggerInterface;
use Throwable;

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
            $dayLabel = CopyParam::dayName($event->dueDate);
            $amount = CopyParam::money($event->expectedAmount->toMinor(), $event->expectedAmount->currency());

            // Low confidence hedges BOTH halves: a firm title over a hedged
            // body reads as a promise the next sentence takes back.
            $copy = $event->confidenceLow
                ? NotificationCopySpec::of(
                    CopyLine::of('notifications::copy.title.payment_reminder_hedged', ['day' => $dayLabel]),
                    CopyLine::of('notifications::copy.body.payment_reminder_hedged', ['name' => $event->displayName, 'day' => $dayLabel, 'amount' => $amount]),
                )
                : NotificationCopySpec::of(
                    CopyLine::of('notifications::copy.title.payment_reminder_confident', ['day' => $dayLabel]),
                    CopyLine::of('notifications::copy.body.payment_reminder_confident', ['name' => $event->displayName, 'day' => $dayLabel, 'date' => CopyParam::shortDate($event->dueDate), 'amount' => $amount]),
                );

            $draft = $this->copyRenderer->forUser($event->userId, fn (): NotificationDraft => NotificationDraft::fromCopy(
                userId: $event->userId,
                triggerType: DeterministicKeyDeriver::TRIGGER_PAYMENT_REMINDER,
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
