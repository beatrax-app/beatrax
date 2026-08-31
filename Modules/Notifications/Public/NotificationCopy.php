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
    // Keyed by the enum's own backing value, so a chip can only be declared
    // for a trigger that exists.

    // The Receipt chip's envelope ends in an invisible U+FE0F; without it the
    // two phone engines disagree about whether it is a picture or a glyph.
    /**
     * @var array<string, array{glyph: string, key: string}>
     *
     * @link ../../../.docs/conventions/emoji-presentation-selector.md
     */
    private const array TYPE_CHIPS = [
        NotificationTrigger::ImportFinished->value => ['glyph' => '⊕', 'key' => 'import'],
        NotificationTrigger::ReceiptsFound->value => ['glyph' => '✉️', 'key' => 'receipt'],
        NotificationTrigger::ManualEntryRecorded->value => ['glyph' => '€', 'key' => 'cash'],
        NotificationTrigger::MigrationFinished->value => ['glyph' => '⇥', 'key' => 'migration'],
        NotificationTrigger::DriftChanged->value => ['glyph' => '⚠', 'key' => 'drift'],
        NotificationTrigger::ForecastShortfall->value => ['glyph' => '▽', 'key' => 'shortfall'],
        NotificationTrigger::PaymentReminder->value => ['glyph' => '◷', 'key' => 'reminder'],
        NotificationTrigger::PositionDigest->value => ['glyph' => '◆', 'key' => 'digest'],
        NotificationTrigger::BudgetNudge->value => ['glyph' => '⊙', 'key' => 'budget'],
        NotificationTrigger::SavingsPrompt->value => ['glyph' => '◎', 'key' => 'savings'],
        NotificationTrigger::IcsStatementReady->value => ['glyph' => '▤', 'key' => 'statement'],
    ];

    // A kind this build cannot name: a trigger type a newer release writes, or
    // the empty string SensitiveColumnCodec substitutes for a sensitive column
    // it could not open. The chip is aria-hidden decoration either way, so a
    // placeholder glyph is the honest render and a throw never was.
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
    public static function typeChip(string $triggerType): array
    {
        $chip = self::TYPE_CHIPS[$triggerType] ?? self::TYPE_CHIP_UNNAMED;

        return [
            'glyph' => $chip['glyph'],
            'word' => Lang::get('notifications::row.chip.'.$chip['key']),
        ];
    }

    // The caller's own view of the fallback above, so a reader that has to
    // report an unnamed row does not re-derive membership from the chip it
    // got back.
    public static function names(string $triggerType): bool
    {
        return array_key_exists($triggerType, self::TYPE_CHIPS);
    }
}
