<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Enums;

use Modules\Notifications\Public\Enums\NotificationTrigger;

// The re-derivations a keyed request can run for content a keyless process was
// refused. The first two are marked by the scheduled pass itself, before it
// reads anything; the third by NotificationWriter, because the triggers it
// covers have no scheduled pass of their own to ask.
/**
 * @link ../../../../.docs/features/mobile/background-sync-cannot-hold-the-key.md#the-scheduled-passes-that-cannot-write-either
 */
enum DeferredNotificationPass: string
{
    case BudgetNudges = 'budget-nudges';

    case DailyTriggers = 'daily-triggers';

    case WithheldTriggers = 'withheld-triggers';

    // What running this pass is expected to produce. Held here so the guard in
    // tests/Contracts can check every trigger a keyless process can raise
    // against something that re-derives it, rather than against a reviewer
    // remembering to look.
    /**
     * @return list<NotificationTrigger>
     */
    public function reDerives(): array
    {
        return match ($this) {
            self::BudgetNudges => [NotificationTrigger::BudgetNudge],
            self::DailyTriggers => [
                NotificationTrigger::PaymentReminder,
                NotificationTrigger::PositionDigest,
                NotificationTrigger::SavingsPrompt,
            ],
            self::WithheldTriggers => [
                NotificationTrigger::DriftChanged,
                NotificationTrigger::ForecastShortfall,
                NotificationTrigger::IcsStatementReady,
            ],
        };
    }
}
