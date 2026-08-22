<?php

declare(strict_types=1);

namespace Modules\Notifications\Public;

use Modules\Notifications\Internal\Support\DeterministicKeyDeriver;

/**
 * @link ../../../.docs/features/sync/sensitive-columns-at-rest.md
 */
final class NotificationCopy
{
    // Only the type chip lives here; titles and bodies moved to the
    // notifications::copy lang files, rendered by NotificationCopyRenderer.
    /**
     * @var array<string, array{glyph: string, word: string}>
     */
    private const TYPE_CHIPS = [
        DeterministicKeyDeriver::TRIGGER_IMPORT_FINISHED => ['glyph' => '⊕', 'word' => 'Import'],
        DeterministicKeyDeriver::TRIGGER_RECEIPTS_FOUND => ['glyph' => '✉', 'word' => 'Receipt'],
        DeterministicKeyDeriver::TRIGGER_DRIFT_CHANGED => ['glyph' => '⚠', 'word' => 'Drift'],
        DeterministicKeyDeriver::TRIGGER_FORECAST_SHORTFALL => ['glyph' => '▽', 'word' => 'Shortfall'],
        DeterministicKeyDeriver::TRIGGER_PAYMENT_REMINDER => ['glyph' => '◷', 'word' => 'Reminder'],
        DeterministicKeyDeriver::TRIGGER_POSITION_DIGEST => ['glyph' => '◆', 'word' => 'Digest'],
        DeterministicKeyDeriver::TRIGGER_BUDGET_NUDGE => ['glyph' => '⊙', 'word' => 'Budget'],
        DeterministicKeyDeriver::TRIGGER_SAVINGS_PROMPT => ['glyph' => '◎', 'word' => 'Savings'],
        DeterministicKeyDeriver::TRIGGER_ICS_STATEMENT_READY => ['glyph' => '▤', 'word' => 'Statement'],
    ];

    // A kind this build cannot name: a trigger type a newer release writes, or
    // the empty string SensitiveColumnCodec substitutes for a sensitive column
    // it could not open. The chip is aria-hidden decoration either way, so a
    // placeholder glyph is the honest render and a throw never was.
    /**
     * @var array{glyph: string, word: string}
     */
    private const TYPE_CHIP_UNNAMED = ['glyph' => '◌', 'word' => 'Notice'];

    /**
     * @return array{glyph: string, word: string}
     */
    public static function typeChip(string $triggerType): array
    {
        return self::TYPE_CHIPS[$triggerType] ?? self::TYPE_CHIP_UNNAMED;
    }

    // The caller's own view of the fallback above, so a reader that has to
    // report an unnamed row does not re-derive membership from the chip it
    // got back.
    public static function names(string $triggerType): bool
    {
        return array_key_exists($triggerType, self::TYPE_CHIPS);
    }
}
