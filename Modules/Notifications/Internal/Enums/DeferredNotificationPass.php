<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Enums;

// The scheduled passes whose ONLY output is notification content. Every one of
// them writes `notifications.title/body/params/trigger_type`, which
// SensitiveColumnCodec refuses to seal in a process holding no app-lock key —
// and an OS-scheduled task is always such a process.
/**
 * @link ../../../../.docs/features/mobile/background-sync-cannot-hold-the-key.md#the-scheduled-passes-that-cannot-write-either
 */
enum DeferredNotificationPass: string
{
    case BudgetNudges = 'budget-nudges';

    case DailyTriggers = 'daily-triggers';
}
