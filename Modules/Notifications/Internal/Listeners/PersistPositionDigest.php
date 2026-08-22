<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Listeners;

use Illuminate\Contracts\Routing\UrlGenerator;
use Modules\Budgets\Public\Enums\BudgetProgressStatus;
use Modules\Core\Public\Navigation\Destination;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Notifications\Internal\Support\CopyLine;
use Modules\Notifications\Internal\Support\CopyParam;
use Modules\Notifications\Internal\Support\DeterministicKeyDeriver;
use Modules\Notifications\Internal\Support\NotificationCopyRenderer;
use Modules\Notifications\Internal\Support\NotificationCopySpec;
use Modules\Notifications\Internal\Support\NotificationDraft;
use Modules\Notifications\Internal\Support\NotificationWriter;
use Modules\Position\Public\Dto\PositionSummaryDto;
use Modules\Position\Public\Events\PositionDigestDue;
use Psr\Log\LoggerInterface;
use Throwable;

final class PersistPositionDigest
{
    public function __construct(
        private readonly NotificationWriter $writer,
        private readonly UrlGenerator $urls,
        private readonly LoggerInterface $log,
        private readonly NotificationCopyRenderer $copyRenderer,
    ) {}

    public function handle(PositionDigestDue $event): void
    {
        try {
            $copy = NotificationCopySpec::make(
                CopyLine::of($event->cadence === 'daily'
                    ? 'notifications::copy.title.position_digest_daily'
                    : 'notifications::copy.title.position_digest_weekly'),
                $this->composeBody($event->position),
            );

            $draft = $this->copyRenderer->forUser($event->userId, fn (): NotificationDraft => NotificationDraft::fromCopy(
                userId: $event->userId,
                triggerType: DeterministicKeyDeriver::TRIGGER_POSITION_DIGEST,
                subjectKey: 'position',
                occurrence: $event->occurrence,
                copy: $copy,
                params: ['target_kind' => 'dashboard'],
                deepLinkRoute: Destination::Dashboard->urlFrom($this->urls),
            ));
            $this->writer->write($draft);
        } catch (Throwable $e) {
            // Swallow - a failed persist must never break the
            // originating digest job run.
            $this->log->error('PersistPositionDigest: failed to persist position digest', [
                ...SafeExceptionContext::describe($e),
                'userId' => $event->userId,
                'cadence' => $event->cadence,
            ]);
        }
    }

    // One-line body summarising $position, as the sentences it is built from
    // rather than the built sentence. Amounts ride as value plus currency for
    // the same reason: a string formatted here freezes in whatever locale this
    // process happens to hold, and a scheduled job holds the app default.
    /**
     * @return list<CopyLine>
     */
    private function composeBody(PositionSummaryDto $position): array
    {
        $summary = $position->summary;

        $nothingNotable = $summary->isFirstRun
            && $position->budgets === []
            && $position->upcoming === []
            && ! $position->shortfallAhead;

        if ($nothingNotable) {
            return [CopyLine::of('notifications::copy.digest.nothing_notable')];
        }

        $parts = [CopyLine::of('notifications::copy.digest.flow', [
            'in' => CopyParam::money($summary->inflow->toMinor(), $summary->inflow->currency()),
            'out' => CopyParam::money($summary->outflow->toMinor(), $summary->outflow->currency()),
            'net' => CopyParam::money($summary->net->toMinor(), $summary->net->currency()),
        ])];

        if ($position->budgets !== []) {
            $overBudgetMinor = 0;
            $currency = 'EUR';
            foreach ($position->budgets as $row) {
                $currency = $row->currency;
                if ($row->status === BudgetProgressStatus::Over) {
                    $overBudgetMinor += -$row->remainingMinor();
                }
            }
            if ($overBudgetMinor > 0) {
                $parts[] = CopyLine::of('notifications::copy.digest.over_budget', ['amount' => CopyParam::money($overBudgetMinor, $currency)]);
            }
        }

        if ($position->upcoming !== []) {
            $parts[] = CopyLine::plural('notifications::copy.digest.payments_due', count($position->upcoming));
        }

        if ($position->shortfallAhead) {
            $parts[] = CopyLine::of('notifications::copy.digest.shortfall');
        }

        return $parts;
    }
}
