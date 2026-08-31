<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Internal\Enums;

// What the dashboard tile's roll-up is allowed to claim. The query counts only
// the alerts that make a subscription dearer, so a zero total means there are
// none — not a rise of nothing, which is what a hard-coded up arrow beside
// EUR 0.00 told the reader on a page whose every open alert was a price drop.
enum AnnualImpactTrend: string
{
    case Rising = 'rising';

    case Flat = 'flat';

    public static function forMinor(int $totalMinor): self
    {
        return $totalMinor > 0 ? self::Rising : self::Flat;
    }

    public function glyph(): string
    {
        return match ($this) {
            self::Rising => '↗',
            self::Flat => '',
        };
    }

    public function impactKey(): string
    {
        return match ($this) {
            self::Rising => 'drift-alerts::dashboard.impact_rising',
            self::Flat => 'drift-alerts::dashboard.impact_flat',
        };
    }
}
