<?php

declare(strict_types=1);

namespace Modules\Notifications\Public\Services;

use Carbon\CarbonImmutable;
use Modules\Core\Models\User;
use Modules\Notifications\Internal\Support\DeterministicKeyDeriver;
use Modules\Notifications\Public\Dto\DeliveryDecision;
use Modules\Notifications\Public\Dto\NotificationPreferencesDto;
use Psr\Log\LoggerInterface;

final class SuppressionEvaluator
{
    // Trigger types with no toggle. Without this list they would hit
    // triggerEnabled()'s default arm, which suppresses and logs a wiring bug.
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

    // hideDetails reflects the stored preference whatever the outcome.
    public function shouldDeliver(int $userId, string $triggerType, CarbonImmutable $at): DeliveryDecision
    {
        /** @var User $user */
        $user = User::query()->findOrFail($userId);

        $prefs = $this->preferences->forCurrentDevice($user);

        return match (true) {
            $this->seeding => new DeliveryDecision(false, 'seeding', $prefs->hideDetails),
            ! $this->triggerEnabled($prefs, $triggerType) => new DeliveryDecision(false, 'trigger_disabled', $prefs->hideDetails),
            $this->insideQuietHours($prefs, $at) => new DeliveryDecision(false, 'quiet_hours', $prefs->hideDetails),
            default => new DeliveryDecision(true, 'ok', $prefs->hideDetails),
        };
    }

    // Restores the prior flag in a finally, so nesting is safe. The demo
    // seeder and feature tests wrap dispatch in this so rows are stored
    // without ever reaching the OS.
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
            DeterministicKeyDeriver::TRIGGER_POSITION_DIGEST => ! $prefs->digestCadence->isOff(),
            // No permissive default arm: an unrecognised trigger must fail
            // loudly rather than bypass every toggle and quiet-hours window.
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

    // Half-open [from, to). When from > to the window spans midnight, so the
    // test becomes time >= from OR time < to.
    private function insideQuietHours(NotificationPreferencesDto $prefs, CarbonImmutable $at): bool
    {
        $from = $prefs->quietHoursFrom;
        $to = $prefs->quietHoursTo;

        if (! $prefs->quietHoursEnabled || $from === null || $to === null || $from === $to) {
            return false;
        }

        $now = $at->format('H:i');

        return $from < $to
            ? ($now >= $from && $now < $to)
            : ($now >= $from || $now < $to);
    }
}
