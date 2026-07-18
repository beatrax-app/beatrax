<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Listeners;

use Modules\Core\Models\User;
use Modules\DriftAlerts\Public\Events\SavingsPromptDue;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Notifications\Internal\Support\DeterministicKeyDeriver;
use Modules\Notifications\Internal\Support\NotificationWriter;
use Modules\Notifications\Public\NotificationCopy;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Persists Req 7's savings-opportunity prompt into the unified inbox.
 *
 * Subscribes to `Modules\DriftAlerts\Public\Events\SavingsPromptDue` —
 * registered by `NotificationsServiceProvider`'s guarded listener table
 * (18-05); this class's exact name/namespace is what flips that guard on.
 *
 * The whole handler body is wrapped in the never-throw envelope (D-07),
 * cloned from `SyncCaptureListener::handle()`: a failed notification-persist
 * must NEVER break the originating `EmitSavingsPromptsJob` run.
 *
 * The occurrence is `$event->insightKey` — `SavingsInsight::$key` (D-06),
 * already stable across re-runs, so a second push of the same insight
 * derives the same deterministic id and `NotificationWriter`'s
 * `insertOrIgnore` silently collapses it to the existing row.
 *
 * D-25 targets the counterparty for a savings prompt. The event carries only
 * the series id (Req 7 forbids the trigger computing anything new), so the
 * counterparty id is resolved via `RecurringSeriesQuery::counterpartyIdForSeries()`
 * — the same Public seam `SavingsInsightsQuery` itself uses internally. If no
 * counterparty can be resolved (a still-uncategorised series), the params
 * fall back to `series`/`seriesId` rather than emitting a null target.
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

            $this->writer->write(
                userId: $event->userId,
                triggerType: DeterministicKeyDeriver::TRIGGER_SAVINGS_PROMPT,
                subjectKey: (string) $event->seriesId,
                // D-06: the occurrence is the insight's own stable key.
                occurrence: $event->insightKey,
                title: NotificationCopy::TITLE_SAVINGS_PROMPT,
                body: $body,
                params: $this->targetParams($event),
                deepLinkRoute: $event->actionUrl,
            );
        } catch (Throwable $e) {
            // Swallow — a failed persist must NEVER break the originating
            // job run (D-07).
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
