<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Listeners;

use Illuminate\Contracts\Routing\UrlGenerator;
use Modules\Core\Public\Support\Lang;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Notifications\Internal\Support\DeterministicKeyDeriver;
use Modules\Notifications\Internal\Support\NotificationCopyRenderer;
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
            $draft = $this->copyRenderer->forUser($event->userId, fn (): NotificationDraft => new NotificationDraft(
                userId: $event->userId,
                triggerType: DeterministicKeyDeriver::TRIGGER_POSITION_DIGEST,
                subjectKey: 'position',
                occurrence: $event->occurrence,
                title: $event->cadence === 'daily'
                    ? Lang::get('notifications::copy.title.position_digest_daily')
                    : Lang::get('notifications::copy.title.position_digest_weekly'),
                body: $this->composeBody($event->position),
                params: ['target_kind' => 'dashboard'],
                deepLinkRoute: $this->urls->route('dashboard'),
            ));
            $this->writer->write($draft);
        } catch (Throwable $e) {
            // Swallow - a failed persist must never break the
            // originating digest job run.
            $this->log->error('PersistPositionDigest: failed to persist position digest', [
                'exception' => $e->getMessage(),
                'userId' => $event->userId,
                'cadence' => $event->cadence,
            ]);
        }
    }

    // One-line body summarising $position. Every amount is formatted
    // through the Money value object, never a hand-formatted minor-unit
    // value. When nothing is notable, the body says so plainly rather
    // than emitting empty or generic filler.
    private function composeBody(PositionSummaryDto $position): string
    {
        $summary = $position->summary;

        $nothingNotable = $summary->isFirstRun
            && $position->budgets === []
            && $position->upcoming === []
            && ! $position->shortfallAhead;

        if ($nothingNotable) {
            return Lang::get('notifications::copy.digest.nothing_notable');
        }

        $parts = [Lang::get('notifications::copy.digest.flow', [
            'in' => $summary->inflow->format(),
            'out' => $summary->outflow->format(),
            'net' => $summary->net->format(),
        ])];

        if ($position->budgets !== []) {
            $overBudgetMinor = 0;
            $currency = 'EUR';
            foreach ($position->budgets as $row) {
                $currency = $row->currency;
                if ($row->status === 'over') {
                    $overBudgetMinor += -$row->remainingMinor();
                }
            }
            if ($overBudgetMinor > 0) {
                $parts[] = Lang::get('notifications::copy.digest.over_budget', ['amount' => Money::ofMinor($overBudgetMinor, $currency)->format()]);
            }
        }

        if ($position->upcoming !== []) {
            $count = count($position->upcoming);
            $parts[] = Lang::choice('notifications::copy.digest.payments_due', $count, ['count' => $count]);
        }

        if ($position->shortfallAhead) {
            $parts[] = Lang::get('notifications::copy.digest.shortfall');
        }

        return implode(' ', $parts);
    }
}
