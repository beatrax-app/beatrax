<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Support;

use Modules\Budgets\Public\Services\BudgetNudgeDispatch;
use Modules\Core\Models\User;
use Modules\DriftAlerts\Public\Services\SavingsPromptDispatch;
use Modules\Notifications\Internal\Enums\DeferredNotificationPass;
use Modules\Notifications\Public\Services\NotificationPreferenceQuery;
use Modules\Position\Public\Services\PositionDigestDispatch;
use Modules\Recurring\Public\Services\PaymentReminderDispatch;

// Re-derivation, not replay: nothing of the withheld nudge was kept, because
// keeping a rendered title and body on disk is the state the seal exists to
// prevent. Re-running is safe instead — NotificationWriter derives the row id
// from the draft and inserts with insertOrIgnore.
/**
 * @link ../../../../.docs/features/mobile/background-sync-cannot-hold-the-key.md#the-scheduled-passes-that-cannot-write-either
 */
final readonly class DeferredNotificationPassRunner
{
    public function __construct(
        private BudgetNudgeDispatch $nudges,
        private PaymentReminderDispatch $reminders,
        private PositionDigestDispatch $digest,
        private SavingsPromptDispatch $savingsPrompts,
        private NotificationPreferenceQuery $preferences,
    ) {}

    public function run(int $userId, DeferredNotificationPass $pass): void
    {
        match ($pass) {
            DeferredNotificationPass::BudgetNudges => $this->nudges->forUserNow($userId),
            DeferredNotificationPass::DailyTriggers => $this->dailyTriggers($userId),
        };
    }

    private function dailyTriggers(int $userId): void
    {
        /** @var User|null $user */
        $user = User::query()->whereKey($userId)->first();

        if ($user === null) {
            return;
        }

        $preferences = $this->preferences->forCurrentDevice($user);

        $this->reminders->forUserNow($userId, $preferences->reminderLeadDays);
        $this->digest->forUserNow($userId, $preferences->digestCadence);
        $this->savingsPrompts->forUserNow($userId);
    }
}
