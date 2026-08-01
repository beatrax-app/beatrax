<?php

declare(strict_types=1);

namespace Modules\Core\Public\Enums;

// The single source of truth for the appearance preference the UI ships.
// Every seam that enumerates the choice — the settings switcher, the
// validator's allow-list, the layout's server-side dark resolution —
// derives from these cases, not a repeated 'light'/'dark'/'system' literal.
/**
 * @link ../../../../.docs/features/core/architecture.md
 */
enum Theme: string
{
    case Light = 'light';

    case Dark = 'dark';

    case System = 'system';

    // The fallback used for guests and for a user row whose theme column
    // was never set. "system" defers the light/dark decision to the OS
    // signal (desktop bundle) or the pre-paint prefers-color-scheme read.
    public const string DEFAULT = self::System->value;

    // Maps a stored/guest theme string onto a case, collapsing null and
    // any unrecognised value onto the safe DEFAULT so a stale column can
    // never reach the layout as an unhandled literal.
    public static function coerce(?string $value): self
    {
        return $value === null
            ? self::from(self::DEFAULT)
            : (self::tryFrom($value) ?? self::from(self::DEFAULT));
    }

    // Whether the theme resolves to a dark render given the OS signal.
    // An explicit Dark is always dark; System is dark only when the OS
    // reported dark. Light and a System with a null/light OS signal are
    // light — the pre-paint script corrects System client-side.
    public function isDark(?string $osTheme): bool
    {
        return $this === self::Dark
            || ($this === self::System && $osTheme === self::Dark->value);
    }

    // Whether the pre-paint prefers-color-scheme script must run for this
    // theme: only when System is chosen and the OS gave no explicit answer
    // (null), so the client read is the authoritative source in that branch.
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
