<?php

declare(strict_types=1);

namespace Modules\Core\Public\Enums;

use Carbon\CarbonInterface;

// The three windows a review queue defers by. Drift alerts, anomaly alerts
// and recurring-series suggestions all offer the same one week / one month /
// three months, and the backing values are the wire values their blades and
// their Livewire snooze methods exchange.
enum SnoozeWindow: string
{
    case OneWeek = '1w';

    case OneMonth = '1m';

    case ThreeMonths = '3m';

    // Measures from the moment it is handed rather than reading a clock, so
    // the caller's injected Clock stays the only source of "now" and
    // CarbonImmutable::setTestNow() still fixes what the targets come out as.
    public function targetFrom(CarbonInterface $now): string
    {
        $target = match ($this) {
            self::OneWeek => $now->addWeek(),
            self::OneMonth => $now->addMonth(),
            self::ThreeMonths => $now->addMonths(3),
        };

        return $target->toIso8601String();
    }

    // Each queue keeps its own copy of the three labels under its own
    // namespace, so the shared half is the leaf and the caller supplies the
    // group it lives under.
    public function labelKey(string $group): string
    {
        return $group.'.snooze_'.$this->value;
    }

    /**
     * @return array<string, string>
     */
    public static function targetsFrom(CarbonInterface $now): array
    {
        $targets = [];
        foreach (self::cases() as $window) {
            $targets[$window->value] = $window->targetFrom($now);
        }

        return $targets;
    }
}
