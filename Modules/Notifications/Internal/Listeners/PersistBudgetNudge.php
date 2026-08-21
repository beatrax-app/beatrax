<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Listeners;

use Illuminate\Contracts\Routing\UrlGenerator;
use Modules\Budgets\Public\Events\BudgetThresholdCrossed;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Notifications\Internal\Support\CopyLine;
use Modules\Notifications\Internal\Support\CopyParam;
use Modules\Notifications\Internal\Support\DeterministicKeyDeriver;
use Modules\Notifications\Internal\Support\NotificationCopyRenderer;
use Modules\Notifications\Internal\Support\NotificationCopySpec;
use Modules\Notifications\Internal\Support\NotificationDraft;
use Modules\Notifications\Internal\Support\NotificationWriter;
use Psr\Log\LoggerInterface;
use Throwable;

final class PersistBudgetNudge
{
    public function __construct(
        private readonly NotificationWriter $writer,
        private readonly UrlGenerator $urls,
        private readonly LoggerInterface $log,
        private readonly NotificationCopyRenderer $copyRenderer,
    ) {}

    public function handle(BudgetThresholdCrossed $event): void
    {
        try {
            $copy = NotificationCopySpec::of(
                CopyLine::of('notifications::copy.title.budget_nudge'),
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
                triggerType: DeterministicKeyDeriver::TRIGGER_BUDGET_NUDGE,
                subjectKey: (string) $event->categoryId,
                // Occurrence = the budget period, which is what makes a
                // second same-period crossing a silent no-op.
                occurrence: $event->period,
                copy: $copy,
                params: ['target_kind' => 'budget', 'target_id' => $event->categoryId],
                deepLinkRoute: $this->urls->route('budgets.index'),
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
