<?php

declare(strict_types=1);

namespace Modules\Notifications\Public\Services;

use Carbon\CarbonImmutable;
use Modules\Core\Models\User;
use Modules\Notifications\Internal\Support\DeterministicKeyDeriver;
use Modules\Notifications\Public\Dto\DeliveryDecision;
use Modules\Notifications\Public\Dto\NotificationPreferencesDto;
use Psr\Log\LoggerInterface;

/**
 * @link ../../../../.docs/features/notifications/architecture.md
 */
final class SuppressionEvaluator
{
    // Trigger types that carry no control-list toggle - always
    // deliverable, rather than falling into triggerEnabled()'s default
    // arm, whose "unrecognised trigger" fallback suppresses + logs a
    // warning for a genuinely forgotten pref-wiring bug.
    private const ALWAYS_DELIVERABLE = [
        DeterministicKeyDeriver::TRIGGER_IMPORT_FINISHED,
        DeterministicKeyDeriver::TRIGGER_RECEIPTS_FOUND,
        DeterministicKeyDeriver::TRIGGER_DRIFT_CHANGED,
        DeterministicKeyDeriver::TRIGGER_FORECAST_SHORTFALL,
        DeterministicKeyDeriver::TRIGGER_ICS_STATEMENT_READY,
    ];

    private bool $seeding = false;

    public function __construct(
        private readonly NotificationPreferenceQuery $preferences,
        private readonly LoggerInterface $logger,
    ) {}

    // Evaluation order: seeding -> suppress 'seeding'; per-trigger
    // toggle off -> suppress 'trigger_disabled'; inside quiet-hours ->
    // suppress 'quiet_hours'; otherwise -> deliver 'ok'. hideDetails
    // always reflects the stored preference regardless of the outcome.
    public function shouldDeliver(int $userId, string $triggerType, CarbonImmutable $at): DeliveryDecision
    {
        /** @var User $user */
        $user = User::query()->findOrFail($userId);

        $prefs = $this->preferences->forCurrentDevice($user);

        if ($this->seeding) {
            return new DeliveryDecision(false, 'seeding', $prefs->hideDetails);
        }

        if (! $this->triggerEnabled($prefs, $triggerType)) {
            return new DeliveryDecision(false, 'trigger_disabled', $prefs->hideDetails);
        }

        if ($this->insideQuietHours($prefs, $at)) {
            return new DeliveryDecision(false, 'quiet_hours', $prefs->hideDetails);
        }

        return new DeliveryDecision(true, 'ok', $prefs->hideDetails);
    }

    // Runs $callback with delivery globally suppressed, restoring the
    // prior flag value in a finally (reentrant-safe). The demo seeder
    // and feature tests wrap their event dispatch in this so rows are
    // stored but never pushed to the OS.
    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function suppressDelivery(callable $callback): mixed
    {
        $previous = $this->seeding;
        $this->seeding = true;

        try {
            return $callback();
        } finally {
            $this->seeding = $previous;
        }
    }

    private function triggerEnabled(NotificationPreferencesDto $prefs, string $triggerType): bool
    {
        if (in_array($triggerType, self::ALWAYS_DELIVERABLE, strict: true)) {
            return true;
        }

        return match ($triggerType) {
            DeterministicKeyDeriver::TRIGGER_PAYMENT_REMINDER => $prefs->remindersEnabled,
            DeterministicKeyDeriver::TRIGGER_BUDGET_NUDGE => $prefs->budgetNudgesEnabled,
            DeterministicKeyDeriver::TRIGGER_SAVINGS_PROMPT => $prefs->savingsPromptsEnabled,
            DeterministicKeyDeriver::TRIGGER_POSITION_DIGEST => $prefs->digestCadence !== 'off',
            // No permissive default arm: an unrecognised trigger type
            // fails loudly at review rather than silently bypassing
            // every toggle and quiet-hours window.
            default => $this->rejectUnknownTrigger($triggerType),
        };
    }

    private function rejectUnknownTrigger(string $triggerType): bool
    {
        $this->logger->warning(
            'SuppressionEvaluator: unknown trigger type encountered — suppressing delivery.',
            ['trigger_type' => $triggerType],
        );

        return false;
    }

    // True when $at's local time falls inside the half-open window
    // [quiet_hours_from, quiet_hours_to). Handles the wrap-around case
    // (22:00-08:00 spans midnight): when from > to, the window is
    // time >= from OR time < to.
    private function insideQuietHours(NotificationPreferencesDto $prefs, CarbonImmutable $at): bool
    {
        if (! $prefs->quietHoursEnabled) {
            return false;
        }

        $from = $prefs->quietHoursFrom;
        $to = $prefs->quietHoursTo;

        if ($from === null || $to === null) {
            return false;
        }

        $now = $at->format('H:i');

        if ($from === $to) {
            return false;
        }

        if ($from < $to) {
            return $now >= $from && $now < $to;
        }

        return $now >= $from || $now < $to;
    }
}
