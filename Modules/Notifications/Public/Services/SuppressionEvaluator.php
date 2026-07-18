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
 * The ONE place notification-delivery suppression is decided (D-38
 * invariant 4). Both `DispatchOsNotification` (18-11) and
 * `DispatchMobileNotification` (18-15) call `shouldDeliver()`; plan 18-17's
 * BoundaryArchTest proves nothing else evaluates toggles / quiet hours /
 * the seeding flag.
 *
 * Lives on the module's `Public\Services` surface (relocated from
 * `Internal\Support` during 18-11) because both consumers are OTHER
 * modules' delivery adapters — `Modules\Desktop` and (18-15)
 * `Modules\Mobile` — and the cross-module `BoundaryRule` only admits
 * `Public\*` / `Models\*` imports. `DeliveryDecision` moved alongside it to
 * `Public\Dto` for the same reason: an adapter that cannot see the return
 * type cannot call the method.
 *
 * D-08 / Req 9 shared contract (binding): this class decides DELIVERY only.
 * It NEVER prevents a notification row from being written and nothing is
 * ever silently dropped — a suppressed decision means "store the row but do
 * not push it to the OS", exactly D-08's per-trigger and D-43's seeding
 * semantics. The writer (18-05) always persists the row regardless of what
 * this returns.
 *
 * Preferences are read via `NotificationPreferenceQuery::forCurrentDevice()`
 * so an unpaired / preference-less device transparently gets the D-16/D-19
 * defaults, never an error.
 *
 * The instance-level seeding flag (D-43) is scoped here — NOT in each
 * delivery adapter — so "store but do not deliver" has exactly one home
 * (D-38 invariant 4) and plans 18-11/18-15 never both edit a shared helper.
 */
final class SuppressionEvaluator
{
    /** Trigger types that carry no D-36 control-list toggle — always deliverable. */
    private const ALWAYS_DELIVERABLE = [
        DeterministicKeyDeriver::TRIGGER_IMPORT_FINISHED,
        DeterministicKeyDeriver::TRIGGER_RECEIPTS_FOUND,
        DeterministicKeyDeriver::TRIGGER_DRIFT_CHANGED,
        DeterministicKeyDeriver::TRIGGER_FORECAST_SHORTFALL,
    ];

    private bool $seeding = false;

    public function __construct(
        private readonly NotificationPreferenceQuery $preferences,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Decides whether $triggerType should be delivered on THIS device at
     * $at, and whether financial detail must be hidden (D-24).
     *
     * Evaluation order (D-08/D-19/D-43):
     *   1. seeding flag set        -> suppress, reason 'seeding'
     *   2. per-trigger toggle off  -> suppress, reason 'trigger_disabled'
     *   3. inside quiet-hours      -> suppress, reason 'quiet_hours'
     *   4. otherwise               -> deliver,  reason 'ok'
     *
     * `hideDetails` always reflects the stored preference, regardless of
     * the deliver outcome.
     */
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

    /**
     * The D-43 seeding/test suppression flag. Runs $callback with delivery
     * globally suppressed, restoring the PRIOR flag value in a `finally`
     * (reentrant-safe). The seeder (18-16) and feature tests wrap their
     * event dispatch in this so rows are stored but never pushed to the OS.
     *
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
            // No permissive default arm (T-18-10): an unrecognised trigger
            // type fails LOUDLY at review rather than silently bypassing
            // every toggle and quiet-hours window. Treated as NOT deliverable
            // and logged so a future ninth type cannot slip through.
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

    /**
     * True when $at's local time falls inside the half-open window
     * `[quiet_hours_from, quiet_hours_to)`. Handles the wrap-around case
     * (22:00-08:00 spans midnight): when `from > to`, the window is
     * `time >= from OR time < to`.
     */
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

        // Wrap-around window (spans midnight).
        return $now >= $from || $now < $to;
    }
}
