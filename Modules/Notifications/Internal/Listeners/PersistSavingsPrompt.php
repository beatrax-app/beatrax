<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Listeners;

use Modules\Core\Models\User;
use Modules\Core\Public\Support\CopyLine;
use Modules\Core\Public\Support\CopyParam;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\DriftAlerts\Public\Events\SavingsPromptDue;
use Modules\Notifications\Internal\Support\NotificationCopyRenderer;
use Modules\Notifications\Internal\Support\NotificationCopySpec;
use Modules\Notifications\Internal\Support\NotificationDraft;
use Modules\Notifications\Internal\Support\NotificationWriter;
use Modules\Notifications\Public\Enums\NotificationTrigger;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class PersistSavingsPrompt
{
    public function __construct(
        private NotificationWriter $writer,
        private RecurringSeriesQuery $recurring,
        private LoggerInterface $log,
        private NotificationCopyRenderer $copyRenderer,
    ) {}

    public function handle(SavingsPromptDue $event): void
    {
        try {
            // The title names no cheaper plan: SavingsInsightsQuery raises three
            // kinds and only one of them found one. The body IS the insight's own
            // line, named by key rather than carried as a rendered sentence — the
            // emitting job is hourly and resolves in no reader's language.
            $copy = NotificationCopySpec::of(
                CopyLine::of('notifications::copy.title.savings_prompt'),
                CopyLine::of($event->messageKey, [
                    'name' => $event->name,
                    'monthly' => CopyParam::money($event->monthlyMinor, $event->currency),
                ]),
            );

            $draft = $this->copyRenderer->forUser($event->userId, fn (): NotificationDraft => NotificationDraft::fromCopy(
                userId: $event->userId,
                triggerType: NotificationTrigger::SavingsPrompt,
                subjectKey: (string) $event->seriesId,
                occurrence: $event->insightKey,
                copy: $copy,
                params: $this->targetParams($event),
                deepLinkRoute: $event->actionUrl,
            ));
            $this->writer->write($draft);
        } catch (Throwable $e) {
            // Swallow - a failed persist must never break the
            // originating job run.
            $this->log->error('PersistSavingsPrompt: failed to persist savings prompt', [
                ...SafeExceptionContext::describe($e),
                'seriesId' => $event->seriesId,
                'userId' => $event->userId,
            ]);
        }
    }

    /**
     * @return array{target_kind: string, target_id: int}
     */
    private function targetParams(SavingsPromptDue $event): array
    {
        /** @var User|null $user */
        $user = User::query()->where('id', $event->userId)->first();
        if ($user === null) {
            return ['target_kind' => 'series', 'target_id' => $event->seriesId];
        }

        $counterpartyId = $this->recurring->counterpartyIdForSeries($event->seriesId, $user);
        if ($counterpartyId === null) {
            return ['target_kind' => 'series', 'target_id' => $event->seriesId];
        }

        return ['target_kind' => 'counterparty', 'target_id' => $counterpartyId];
    }
}
