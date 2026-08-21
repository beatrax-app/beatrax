<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Listeners;

use Modules\Core\Models\User;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\DriftAlerts\Public\Events\SavingsPromptDue;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Notifications\Internal\Support\CopyLine;
use Modules\Notifications\Internal\Support\DeterministicKeyDeriver;
use Modules\Notifications\Internal\Support\NotificationCopyRenderer;
use Modules\Notifications\Internal\Support\NotificationCopySpec;
use Modules\Notifications\Internal\Support\NotificationDraft;
use Modules\Notifications\Internal\Support\NotificationWriter;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;
use Psr\Log\LoggerInterface;
use Throwable;

final class PersistSavingsPrompt
{
    public function __construct(
        private readonly NotificationWriter $writer,
        private readonly RecurringSeriesQuery $recurring,
        private readonly LoggerInterface $log,
        private readonly NotificationCopyRenderer $copyRenderer,
    ) {}

    public function handle(SavingsPromptDue $event): void
    {
        try {
            $monthlyText = Money::ofMinor($event->monthlyMinor, $event->currency)
                ->format();

            $copy = NotificationCopySpec::of(
                CopyLine::of('notifications::copy.title.savings_prompt'),
                CopyLine::of('notifications::copy.body.savings_prompt', ['message' => $event->message, 'monthly' => $monthlyText]),
            );

            $draft = $this->copyRenderer->forUser($event->userId, fn (): NotificationDraft => NotificationDraft::fromCopy(
                userId: $event->userId,
                triggerType: DeterministicKeyDeriver::TRIGGER_SAVINGS_PROMPT,
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
