<?php

declare(strict_types=1);

namespace Modules\Notifications\Public;

use Modules\Core\Public\Support\Lang;
use Modules\Notifications\Public\Enums\NotificationTrigger;

/**
 * @link ../../../.docs/features/sync/sensitive-columns-at-rest.md
 */
final class NotificationCopy
{
    // Only the type chip lives here; titles and bodies moved to the
    // notifications::copy lang files, rendered by NotificationCopyRenderer.
    // The chip itself is NotificationTrigger::chip(), so the vocabulary cannot
    // fall behind the enum that names it.

    // Null reaches here from one place: a stored trigger_type no case can
    // represent — a kind a newer release writes, or the empty string
    // SensitiveColumnCodec substitutes for a column it could not open. The
    // reader is told which it is by the row, not by this decoration.
    /**
     * @var array{glyph: string, key: string}
     */
    private const array TYPE_CHIP_UNNAMED = ['glyph' => '◌', 'key' => 'unnamed'];

    // The word is looked up per call, never held: aria-hidden keeps the chip
    // from a screen reader and not from eyes, so a word frozen at the first
    // read would sit in one reader's language beside another's title.
    /**
     * @return array{glyph: string, word: string}
     */
    public static function typeChip(?NotificationTrigger $trigger): array
    {
        $chip = $trigger?->chip() ?? self::TYPE_CHIP_UNNAMED;

        return [
            'glyph' => $chip['glyph'],
            'word' => Lang::get('notifications::row.chip.'.$chip['key']),
        ];
    }
}
