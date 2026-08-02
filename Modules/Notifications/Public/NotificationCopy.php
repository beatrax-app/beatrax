<?php

declare(strict_types=1);

namespace Modules\Notifications\Public;

use InvalidArgumentException;
use Modules\Notifications\Internal\Support\DeterministicKeyDeriver;

/**
 * @link ../../../.docs/features/notifications/architecture.md
 */
final class NotificationCopy
{
    // The type chip shown beside each notification row. The titles/bodies
    // themselves now live in the notifications::copy lang files, rendered per
    // recipient by NotificationCopyRenderer, so only this presentation map and
    // its lookup remain here.
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

    /**
     * @return array{glyph: string, word: string}
     */
    public static function typeChip(string $triggerType): array
    {
        return self::TYPE_CHIPS[$triggerType]
            ?? throw new InvalidArgumentException("NotificationCopy: unknown trigger type '{$triggerType}'.");
    }
}
