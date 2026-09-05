<?php

declare(strict_types=1);

namespace Modules\Notifications\Public\Enums;

// The eleven things that raise a notification. The backing strings are what the
// encrypted trigger_type column already holds, so they are frozen. Another
// used to mean editing three hand-kept lists; missing SuppressionEvaluator's
// wrote the row and then never delivered it, visible only in a log line.
enum NotificationTrigger: string
{
    case ImportFinished = 'import_finished';

    case ReceiptsFound = 'receipts_found';

    case ManualEntryRecorded = 'manual_entry_recorded';

    case DriftChanged = 'drift_changed';

    case ForecastShortfall = 'forecast_shortfall';

    case PaymentReminder = 'payment_reminder';

    case PositionDigest = 'position_digest';

    case BudgetNudge = 'budget_nudge';

    case SavingsPrompt = 'savings_prompt';

    case IcsStatementReady = 'ics_statement_ready';

    case MigrationFinished = 'migration_finished';

    // Seven triggers carry no per-device toggle and are always deliverable;
    // SuppressionEvaluator reads the other four out of the preferences DTO.
    public function requiresToggle(): bool
    {
        return match ($this) {
            self::DriftChanged,
            self::ForecastShortfall,
            self::IcsStatementReady,
            self::ImportFinished,
            self::ManualEntryRecorded,
            self::MigrationFinished,
            self::ReceiptsFound => false,
            self::BudgetNudge,
            self::PaymentReminder,
            self::PositionDigest,
            self::SavingsPrompt => true,
        };
    }

    // No default arm, so a twelfth case is a static-analysis failure here
    // rather than a row rendered under the placeholder glyph with only a log
    // line saying which kind it was. That leaves the placeholder reachable by
    // exactly one thing: a string no case can represent.
    /**
     * @return array{glyph: string, key: string}
     *
     * @link ../../../../.docs/conventions/emoji-presentation-selector.md
     */
    public function chip(): array
    {
        return match ($this) {
            // The Receipt chip's envelope ends in an invisible U+FE0F; without
            // it the two phone engines disagree about whether it is a picture
            // or a glyph.
            self::ReceiptsFound => ['glyph' => '✉️', 'key' => 'receipt'],
            self::ImportFinished => ['glyph' => '⊕', 'key' => 'import'],
            self::ManualEntryRecorded => ['glyph' => '€', 'key' => 'cash'],
            self::MigrationFinished => ['glyph' => '⇥', 'key' => 'migration'],
            self::DriftChanged => ['glyph' => '⚠', 'key' => 'drift'],
            self::ForecastShortfall => ['glyph' => '▽', 'key' => 'shortfall'],
            self::PaymentReminder => ['glyph' => '◷', 'key' => 'reminder'],
            self::PositionDigest => ['glyph' => '◆', 'key' => 'digest'],
            self::BudgetNudge => ['glyph' => '⊙', 'key' => 'budget'],
            self::SavingsPrompt => ['glyph' => '◎', 'key' => 'savings'],
            self::IcsStatementReady => ['glyph' => '▤', 'key' => 'statement'],
        };
    }
}
