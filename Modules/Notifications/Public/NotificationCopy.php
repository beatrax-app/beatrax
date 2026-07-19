<?php

declare(strict_types=1);

namespace Modules\Notifications\Public;

use InvalidArgumentException;
use Modules\Notifications\Internal\Support\DeterministicKeyDeriver;

/**
 * The single copy authority for every notification title AND every type-chip
 * glyph/word pair (D-29). Desktop's `DispatchOsNotification` reads from here
 * instead of declaring its own constants — that duplication is what created
 * the OS-banner-vs-inbox drift risk this class exists to kill.
 *
 * The four reactive titles below are LOCKED — copied byte-for-byte from
 * `Modules\Desktop\Internal\Listeners\DispatchOsNotification`'s original
 * constants. The UI-SPEC forbids rewording them, and `DispatchOsNotification`
 * removes its own copies of these constants in plan 18-11 once its listener
 * is repointed here. Any future change to these four strings requires an
 * explicit UI-SPEC amendment, not a casual edit.
 *
 * The four proactive titles are new (D-23), in the same terse, neutral
 * noun-phrase register as the locked four. Two of them (payment reminder,
 * position digest) carry a discretionary VARIANT method rather than a flat
 * constant — see `paymentReminderTitle()` (D-17 confident/hedged pair) and
 * `positionDigestTitle()` (daily/weekly cadence).
 */
final class NotificationCopy
{
    /** LOCKED (D-29) — verbatim from DispatchOsNotification::TITLE_IMPORT_FINISHED. Do not reword. */
    public const TITLE_IMPORT_FINISHED = 'Import finished';

    /** LOCKED (D-29) — verbatim from DispatchOsNotification::TITLE_RECEIPTS. Do not reword. */
    public const TITLE_RECEIPTS = 'New receipts found';

    /** LOCKED (D-29) — verbatim from DispatchOsNotification::TITLE_DRIFT. Do not reword. */
    public const TITLE_DRIFT = 'A recurring charge changed';

    /** LOCKED (D-29) — verbatim from DispatchOsNotification::TITLE_FORECAST. Do not reword. */
    public const TITLE_FORECAST = 'Cash-flow shortfall ahead';

    /** New (D-23). Confident-variant default; see paymentReminderTitle() for the confident/hedged pair. */
    public const TITLE_PAYMENT_REMINDER = 'Payment due soon';

    /** New (D-23). Weekly-cadence default; see positionDigestTitle() for the daily/weekly pair. */
    public const TITLE_POSITION_DIGEST = 'Your weekly position';

    /** New (D-23). */
    public const TITLE_BUDGET_NUDGE = 'Budget nearly spent';

    /** New (D-23). */
    public const TITLE_SAVINGS_PROMPT = 'A cheaper plan exists';

    /**
     * Phase 19 (Req 14, D-14/D-15) — the ICS "statement ready" nudge.
     * Not part of the original locked 18-UI-SPEC.md § 3 set; added here
     * (Rule 1 fix) because `typeChip()` below THROWS for any trigger_type
     * missing from `TYPE_CHIPS` — without this entry, rendering
     * `/notifications` for a user with this notification would 500.
     */
    public const TITLE_ICS_STATEMENT_READY = 'New ICS statement ready';

    /**
     * The type-chip glyph/word pairs (originally 8, D-46; Phase 19 (19-16)
     * adds a 9th), keyed by `DeterministicKeyDeriver::TRIGGER_*` values
     * (the `trigger_type` column this phase persists). Glyph map for the
     * original 8 taken verbatim from 18-UI-SPEC.md § 3.
     *
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
     * D-17's confident/hedged title pair for payment reminders. `$dayName`
     * is a caller-supplied day label (e.g. "Friday"); `$confidenceLow`
     * mirrors `RecurringSeriesDto`'s next-expected-charge confidence flag.
     */
    public static function paymentReminderTitle(bool $confidenceLow, string $dayName): string
    {
        return $confidenceLow
            ? 'Payment due around '.$dayName
            : 'Payment due '.$dayName;
    }

    /**
     * `$cadence` is the D-34/`NotificationPreferencesDto::digestCadence`
     * value ('daily' | 'weekly'). Any other value falls back to the weekly
     * phrasing rather than throwing — the digest job never dispatches a
     * digest when cadence is 'off', so 'daily'/'weekly' are the only two
     * values this method is ever called with in practice.
     */
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
