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
 * Persists Req 6's over-budget nudge into the unified inbox.
 *
 * Subscribes to `Modules\Budgets\Public\Events\BudgetThresholdCrossed` —
 * registered by `NotificationsServiceProvider`'s guarded listener table
 * (18-05); this class's exact name/namespace is what flips that guard on.
 *
 * The whole handler body is wrapped in the never-throw envelope (D-07),
 * cloned from `SyncCaptureListener::handle()`: a failed notification-
 * persist must NEVER break the originating `EmitBudgetNudgesJob` run.
 *
 * The occurrence is `$event->period` — D-06's budget period. This single
 * choice is what makes Req 6's "crossing again in the same period does not
 * re-fire" true: a second crossing in the same period derives the same
 * deterministic id and `NotificationWriter`'s `insertOrIgnore` silently
 * drops it. Advancing into the next period rolls the occurrence key, so the
 * nudge legitimately re-fires there.
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
                // D-06: the occurrence is the budget PERIOD — the load-bearing
                // choice that makes a second same-period crossing a silent
                // no-op via insertOrIgnore rather than a separate suppression
                // mechanism.
                occurrence: $event->period,
                title: NotificationCopy::TITLE_BUDGET_NUDGE,
                body: $body,
                params: ['target_kind' => 'budget', 'target_id' => $event->categoryId],
                deepLinkRoute: $this->urls->route('budgets.index'),
            );
        } catch (Throwable $e) {
            // Swallow — a failed persist must NEVER break the originating
            // nudge job run (D-07).
            $this->log->error('PersistBudgetNudge: failed to persist budget nudge', [
                'exception' => $e->getMessage(),
                'categoryId' => $event->categoryId,
                'userId' => $event->userId,
            ]);
        }
    }
}
