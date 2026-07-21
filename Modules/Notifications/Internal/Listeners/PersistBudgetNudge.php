<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Listeners;

use Illuminate\Contracts\Routing\UrlGenerator;
use Modules\Budgets\Public\Events\BudgetThresholdCrossed;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Notifications\Internal\Support\DeterministicKeyDeriver;
use Modules\Notifications\Internal\Support\NotificationWriter;
use Modules\Notifications\Public\NotificationCopy;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @link ../../../../.docs/features/notifications/architecture.md
 */
final class PersistBudgetNudge
{
    public function __construct(
        private readonly NotificationWriter $writer,
        private readonly UrlGenerator $urls,
        private readonly LoggerInterface $log,
    ) {}

    public function handle(BudgetThresholdCrossed $event): void
    {
        try {
            $spentText = Money::ofMinor($event->spentMinor, $event->currency)
                ->format($event->currency === 'EUR' ? 'nl_NL' : 'en_US');
            $budgetText = Money::ofMinor($event->budgetMinor, $event->currency)
                ->format($event->currency === 'EUR' ? 'nl_NL' : 'en_US');

            $body = "{$event->categoryName} — {$spentText} of {$budgetText} spent.";

            $this->writer->write(
                userId: $event->userId,
                triggerType: DeterministicKeyDeriver::TRIGGER_BUDGET_NUDGE,
                subjectKey: (string) $event->categoryId,
                // The occurrence is the budget period - the load-bearing
                // choice that makes a second same-period crossing a
                // silent no-op via insertOrIgnore.
                occurrence: $event->period,
                title: NotificationCopy::TITLE_BUDGET_NUDGE,
                body: $body,
                params: ['target_kind' => 'budget', 'target_id' => $event->categoryId],
                deepLinkRoute: $this->urls->route('budgets.index'),
            );
        } catch (Throwable $e) {
            // Swallow - a failed persist must never break the
            // originating nudge job run.
            $this->log->error('PersistBudgetNudge: failed to persist budget nudge', [
                'exception' => $e->getMessage(),
                'categoryId' => $event->categoryId,
                'userId' => $event->userId,
            ]);
        }
    }
}
