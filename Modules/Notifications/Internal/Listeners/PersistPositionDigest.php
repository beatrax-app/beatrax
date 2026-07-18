<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Listeners;

use Illuminate\Contracts\Routing\UrlGenerator;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Notifications\Internal\Support\DeterministicKeyDeriver;
use Modules\Notifications\Internal\Support\NotificationWriter;
use Modules\Notifications\Public\NotificationCopy;
use Modules\Position\Public\Dto\PositionSummaryDto;
use Modules\Position\Public\Events\PositionDigestDue;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Persists the position digest (Req 5) into the unified inbox.
 *
 * Subscribes to `PositionDigestDue` — registered by
 * `NotificationsServiceProvider`'s guarded listener table (18-05); this
 * class's exact name/namespace is what flips that guard on.
 *
 * The whole handler body is wrapped in the never-throw envelope (D-07),
 * cloned from `SyncCaptureListener::handle()`: a failed notification-persist
 * must NEVER break the originating `EmitPositionDigestJob` run.
 *
 * `subjectKey` is the constant `'position'` — there is exactly one position
 * per user, so the subject is not a per-entity id like the other seven
 * trigger types. `occurrence` is `$event->occurrence` (D-06: the digest date
 * for a daily cadence, the ISO week for a weekly one) — the occurrence key
 * is what makes a second same-day/same-week emission collapse into the
 * existing row via `NotificationWriter`'s `insertOrIgnore`.
 *
 * `params.target_kind` is always `'dashboard'` (D-25) — the digest
 * deep-links to the dashboard, which can never be deleted, so
 * `deepLinkDisabled` is never true for a digest.
 */
final class PersistPositionDigest
{
    public function __construct(
        private readonly NotificationWriter $writer,
        private readonly UrlGenerator $urls,
        private readonly LoggerInterface $log,
    ) {}

    public function handle(PositionDigestDue $event): void
    {
        try {
            $this->writer->write(
                userId: $event->userId,
                triggerType: DeterministicKeyDeriver::TRIGGER_POSITION_DIGEST,
                subjectKey: 'position',
                occurrence: $event->occurrence,
                title: NotificationCopy::positionDigestTitle($event->cadence),
                body: $this->composeBody($event->position),
                params: ['target_kind' => 'dashboard'],
                deepLinkRoute: $this->urls->route('dashboard'),
            );
        } catch (Throwable $e) {
            // Swallow — a failed persist must NEVER break the originating
            // digest job run (D-07).
            $this->log->error('PersistPositionDigest: failed to persist position digest', [
                'exception' => $e->getMessage(),
                'userId' => $event->userId,
                'cadence' => $event->cadence,
            ]);
        }
    }

    /**
     * One-line body summarising `$position`. Every amount is formatted
     * through the `Money` value object — never a hand-formatted minor-unit
     * value.
     *
     * D-21: when nothing is notable — no transactions this period, no
     * budgets, no upcoming charges, no shortfall ahead — the body says so
     * plainly rather than emitting empty or generic filler; "nothing
     * notable" is itself the reassurance the digest exists to deliver.
     */
    private function composeBody(PositionSummaryDto $position): string
    {
        $summary = $position->summary;

        $nothingNotable = $summary->isFirstRun
            && $position->budgets === []
            && $position->upcoming === []
            && ! $position->shortfallAhead;

        if ($nothingNotable) {
            return 'Nothing needs your attention.';
        }

        $parts = [sprintf(
            'In %s, out %s, net %s.',
            $summary->inflow->format(),
            $summary->outflow->format(),
            $summary->net->format(),
        )];

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
                $parts[] = sprintf('%s over budget so far.', Money::ofMinor($overBudgetMinor, $currency)->format());
            }
        }

        if ($position->upcoming !== []) {
            $count = count($position->upcoming);
            $parts[] = $count === 1 ? '1 payment due this period.' : $count.' payments due this period.';
        }

        if ($position->shortfallAhead) {
            $parts[] = 'A cash-flow shortfall is ahead.';
        }

        return implode(' ', $parts);
    }
}
