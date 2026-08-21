<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Enums;

/**
 * @link ../../../../.docs/features/desktop/auto-update.md
 */
enum OsFamily: string
{
    case Darwin = 'Darwin';

    case Linux = 'Linux';

    case Windows = 'Windows';

    // PHP_OS_FAMILY also answers BSD, Solaris and Unknown, none of which this
    // app is built for; null is that answer, and every caller has to decide
    // what to do with it rather than fall into another platform's branch.
    public static function current(): ?self
    {
        return self::tryFrom(PHP_OS_FAMILY);
    }

    // electron-builder gives the Windows manifest no suffix at all, so the
    // empty string is Windows' own answer and not a fallback — the shape that
    // silently served latest.yml to every unrecognised OS.
    public function updateManifestSuffix(): string
    {
        return match ($this) {
            self::Darwin => '-mac',
            self::Linux => '-linux',
            self::Windows => '',
        };
    }
}
