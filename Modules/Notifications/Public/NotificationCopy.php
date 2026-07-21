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
    // These four are LOCKED - copied byte-for-byte from the desktop
    // OS-notification adapter's original constants. Do not reword them;
    // any change requires an explicit UI-SPEC amendment.
    public const TITLE_IMPORT_FINISHED = 'Import finished';

    public const TITLE_RECEIPTS = 'New receipts found';

    public const TITLE_DRIFT = 'A recurring charge changed';

    public const TITLE_FORECAST = 'Cash-flow shortfall ahead';

    // Confident-variant default; see paymentReminderTitle() for the
    // confident/hedged pair.
    public const TITLE_PAYMENT_REMINDER = 'Payment due soon';

    // Weekly-cadence default; see positionDigestTitle() for the
    // daily/weekly pair.
    public const TITLE_POSITION_DIGEST = 'Your weekly position';

    public const TITLE_BUDGET_NUDGE = 'Budget nearly spent';

    public const TITLE_SAVINGS_PROMPT = 'A cheaper plan exists';

    public const TITLE_ICS_STATEMENT_READY = 'New ICS statement ready';

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

    // The confident/hedged title pair for payment reminders. $dayName is
    // a caller-supplied day label (e.g. "Friday"); $confidenceLow mirrors
    // RecurringSeriesDto's next-expected-charge confidence flag.
    public static function paymentReminderTitle(bool $confidenceLow, string $dayName): string
    {
        return $confidenceLow
            ? 'Payment due around '.$dayName
            : 'Payment due '.$dayName;
    }

    // $cadence is 'daily'|'weekly'. Any other value falls back to the
    // weekly phrasing rather than throwing - the digest job never
    // dispatches a digest when cadence is 'off'.
    public static function positionDigestTitle(string $cadence): string
    {
        return $cadence === 'daily' ? 'Your daily position' : 'Your weekly position';
    }

    /**
     * @return array{glyph: string, word: string}
     */
    public static function typeChip(string $triggerType): array
    {
        return self::TYPE_CHIPS[$triggerType]
            ?? throw new InvalidArgumentException("NotificationCopy: unknown trigger type '{$triggerType}'.");
    }
}
