<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Public\Events;

/**
 * Dispatched once per existing `SavingsInsight` returned by
 * `SavingsInsightsQuery::forUser()` (Req 7). Every field is copied straight
 * off that DTO — this event computes NOTHING new; it is the trigger, not the
 * insight logic (SEED-010 owns that, and it stays entirely out of scope
 * here).
 *
 * `$insightKey` is `SavingsInsight::$key` (e.g. `'cheaper:'.$seriesId`),
 * already stable and already the D-06 occurrence key for the persisted
 * notification — do not synthesise a new one downstream.
 */
final readonly class SavingsPromptDue
{
    public function __construct(
        public int $userId,
        public string $insightKey,
        public int $seriesId,
        public string $name,
        public int $monthlyMinor,
        public string $currency,
        public string $message,
        public string $actionUrl,
    ) {}
}
