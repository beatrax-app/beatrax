<?php

declare(strict_types=1);

namespace Modules\Core\Public\Enums;

/**
 * @link ../../../../.docs/features/desktop/auto-update.md#the-two-channels
 */
enum UpdateChannel: string
{
    case Stable = 'stable';

    case Preview = 'preview';

    // Stable is the shipped answer and the answer to every question that has no
    // answer: an unreadable row, a column holding a word no release ever used,
    // a first launch before the table exists. A bundle must never find itself
    // on early builds because a string nobody wrote happened to be there.
    public static function fromStored(?string $value): self
    {
        return $value === null ? self::Stable : (self::tryFrom($value) ?? self::Stable);
    }

    // electron-builder names the stable manifest set `latest` and a channel's
    // own set after the channel. The per-platform suffix belongs to OsFamily,
    // so a channel contributes the prefix and nothing else.
    public function manifestPrefix(): string
    {
        return match ($this) {
            self::Stable => 'latest',
            self::Preview => 'beta',
        };
    }
}
