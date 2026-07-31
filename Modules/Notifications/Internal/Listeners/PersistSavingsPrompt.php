<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Listeners;

use Modules\Core\Models\User;
use Modules\DriftAlerts\Public\Events\SavingsPromptDue;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Notifications\Internal\Support\DeterministicKeyDeriver;
use Modules\Notifications\Internal\Support\NotificationDraft;
use Modules\Notifications\Internal\Support\NotificationWriter;
use Modules\Notifications\Public\NotificationCopy;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @link ../../../../.docs/features/notifications/architecture.md
 */
final class PersistSavingsPrompt
{
    public function __construct(
        private readonly NotificationWriter $writer,
        private readonly RecurringSeriesQuery $recurring,
        private readonly LoggerInterface $log,
    ) {}

    public function handle(SavingsPromptDue $event): void
    {
        try {
            $monthlyText = Money::ofMinor($event->monthlyMinor, $event->currency)
                ->format($event->currency === 'EUR' ? 'nl_NL' : 'en_US');

            $body = $event->message.' ('.$monthlyText.'/mo)';

            $this->writer->write(new NotificationDraft(
                userId: $event->userId,
                triggerType: DeterministicKeyDeriver::TRIGGER_SAVINGS_PROMPT,
                subjectKey: (string) $event->seriesId,
                occurrence: $event->insightKey,
                title: NotificationCopy::TITLE_SAVINGS_PROMPT,
                body: $body,
                params: $this->targetParams($event),
                deepLinkRoute: $event->actionUrl,
            ));
        } catch (Throwable $e) {
            // Swallow - a failed persist must never break the
            // originating job run.
            $this->log->error('PersistSavingsPrompt: failed to persist savings prompt', [
                'exception' => $e->getMessage(),
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
