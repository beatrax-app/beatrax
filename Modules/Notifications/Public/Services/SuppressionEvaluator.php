<?php

declare(strict_types=1);

namespace Modules\Notifications\Public\Services;

use Carbon\CarbonImmutable;
use Modules\Core\Models\User;
use Modules\Notifications\Public\Dto\DeliveryDecision;
use Modules\Notifications\Public\Dto\NotificationPreferencesDto;
use Modules\Notifications\Public\Enums\NotificationTrigger;

final class SuppressionEvaluator
{
    private bool $seeding = false;

    public function __construct(
        private readonly NotificationPreferenceQuery $preferences,
    ) {}

    public function shouldDeliver(int $userId, NotificationTrigger $trigger, CarbonImmutable $at): DeliveryDecision
    {
        /** @var User $user */
        $user = User::query()->findOrFail($userId);

        $prefs = $this->preferences->forCurrentDevice($user);

        return match (true) {
            $this->seeding => new DeliveryDecision(false, 'seeding', $prefs->hideDetails),
            ! self::triggerEnabled($prefs, $trigger) => new DeliveryDecision(false, 'trigger_disabled', $prefs->hideDetails),
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

    // No default arm, so a new trigger is a static-analysis failure here
    // rather than a notification written and never delivered. The seven that
    // answer true carry no toggle; NotificationTrigger::requiresToggle() is
    // the same partition, stated where the enum can be asked about it.
    private static function triggerEnabled(NotificationPreferencesDto $prefs, NotificationTrigger $trigger): bool
    {
        return match ($trigger) {
            NotificationTrigger::BudgetNudge => $prefs->budgetNudgesEnabled,
            NotificationTrigger::PaymentReminder => $prefs->remindersEnabled,
            NotificationTrigger::PositionDigest => ! $prefs->digestCadence->isOff(),
            NotificationTrigger::SavingsPrompt => $prefs->savingsPromptsEnabled,
            NotificationTrigger::DriftChanged,
            NotificationTrigger::ForecastShortfall,
            NotificationTrigger::IcsStatementReady,
            NotificationTrigger::ImportFinished,
            NotificationTrigger::ManualEntryRecorded,
            NotificationTrigger::MigrationFinished,
            NotificationTrigger::ReceiptsFound => true,
        };
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
