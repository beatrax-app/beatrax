<?php

declare(strict_types=1);

namespace Modules\Core\Public\Enums;

// Every seam that enumerates the choice — the settings switcher, the validator's
// allow-list, the layout's server-side dark resolution — derives from here.
enum Theme: string
{
    case Light = 'light';

    case Dark = 'dark';

    case System = 'system';

    // "system" defers the light/dark decision to the OS signal (desktop bundle)
    // or the pre-paint prefers-color-scheme read.
    public const string DEFAULT = self::System->value;

    // Null and any unrecognised value collapse onto DEFAULT, so a stale column
    // can never reach the layout as an unhandled literal.
    public static function coerce(?string $value): self
    {
        return $value === null
            ? self::from(self::DEFAULT)
            : (self::tryFrom($value) ?? self::from(self::DEFAULT));
    }

    // System is dark only when the OS reported dark; a System with a null OS
    // signal renders light and the pre-paint script corrects it client-side.
    public function isDark(?string $osTheme): bool
    {
        return $this === self::Dark
            || ($this === self::System && $osTheme === self::Dark->value);
    }

    // Only when System is chosen and the OS gave no answer, so the client read
    // is the authoritative source in that branch.
    public function needsPrePaintScript(?string $osTheme): bool
    {
        return $this === self::System && $osTheme === null;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
