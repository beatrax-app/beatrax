<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Listeners;

use Illuminate\Contracts\Routing\UrlGenerator;
use Modules\Budgets\Public\Events\BudgetThresholdCrossed;
use Modules\Core\Public\Navigation\Destination;
use Modules\Core\Public\Support\CopyLine;
use Modules\Core\Public\Support\CopyParam;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Notifications\Internal\Support\NotificationCopyRenderer;
use Modules\Notifications\Internal\Support\NotificationCopySpec;
use Modules\Notifications\Internal\Support\NotificationDraft;
use Modules\Notifications\Internal\Support\NotificationWriter;
use Modules\Notifications\Public\Enums\NotificationTrigger;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class PersistBudgetNudge
{
    public function __construct(
        private NotificationWriter $writer,
        private UrlGenerator $urls,
        private LoggerInterface $log,
        private NotificationCopyRenderer $copyRenderer,
    ) {}

    // The nudge fires on `spent >= threshold%` with no ceiling, the reader may
    // set that threshold as high as 200%, and the occurrence is the period — so
    // one large charge left "Budget nearly spent" standing over a body reading
    // "250.00 of 100.00 spent".
    private static function titleKey(BudgetThresholdCrossed $event): string
    {
        return match (true) {
            $event->spentMinor > $event->budgetMinor => 'notifications::copy.title.budget_nudge_over',
            $event->spentMinor === $event->budgetMinor => 'notifications::copy.title.budget_nudge_spent',
            default => 'notifications::copy.title.budget_nudge',
        };
    }

    public function handle(BudgetThresholdCrossed $event): void
    {
        try {
            $copy = NotificationCopySpec::of(
                CopyLine::of(self::titleKey($event)),
                CopyLine::of('notifications::copy.body.budget_nudge', [
                    'category' => CopyParam::category(
                        $event->categoryName,
                        $event->categorySlug,
                        $event->categoryNameIsDefault,
                    ),
                    'spent' => CopyParam::money($event->spentMinor, $event->currency),
                    'budget' => CopyParam::money($event->budgetMinor, $event->currency),
                ]),
            );

            $draft = $this->copyRenderer->forUser($event->userId, fn (): NotificationDraft => NotificationDraft::fromCopy(
                userId: $event->userId,
                triggerType: NotificationTrigger::BudgetNudge,
                subjectKey: (string) $event->categoryId,
                // Occurrence = the budget period, which is what makes a
                // second same-period crossing a silent no-op.
                occurrence: $event->period,
                copy: $copy,
                params: ['target_kind' => 'budget', 'target_id' => $event->categoryId],
                deepLinkRoute: Destination::Budgets->urlFrom($this->urls),
            ));
            $this->writer->write($draft);
        } catch (Throwable $e) {
            // Swallow - a failed persist must never break the
            // originating nudge job run.
            $this->log->error('PersistBudgetNudge: failed to persist budget nudge', [
                ...SafeExceptionContext::describe($e),
                'categoryId' => $event->categoryId,
                'userId' => $event->userId,
            ]);
        }
    }
}
