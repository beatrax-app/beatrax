<?php

declare(strict_types=1);

namespace Modules\Import\Public\Enums;

/**
 * Closed enumeration of the eight possible values a transactions row's
 * `payment_type` column can carry. The schema-level paired BEFORE
 * INSERT / BEFORE UPDATE trigger on `transactions.payment_type` rejects
 * any value outside this set; the Eloquent layer casts the column to
 * this enum so consumer code resolves chip rendering data through the
 * enum's own `chipLabel()` and `chipClass()` methods rather than
 * duplicating the glyph-and-word mapping at every render site.
 *
 * `Unknown` is the safe default applied to every existing row at
 * migration time and to every newly-imported row whose hinters cannot
 * agree on a value.
 */
enum PaymentType: string
{
    case Pin = 'pin';
    case Online = 'online';
    case Transfer = 'transfer';
    case DirectDebit = 'direct_debit';
    case Cash = 'cash';
    case Fee = 'fee';
    case Refund = 'refund';
    case Unknown = 'unknown';

    /**
     * Glyph + word rendered inside the `.ptype-chip` UI primitive on the
     * import preview row, the `/transactions` list, and the
     * `/community/mystery-merchants` triage cards. Kept inside the enum
     * so the legend bar copy in the import preview matches the chip
     * copy on every row.
     */
    public function chipLabel(): string
    {
        return match ($this) {
            self::Pin => '⛁ PIN',
            self::Online => '⌘ Online',
            self::Transfer => '↔ Transfer',
            self::DirectDebit => '⤓ Direct debit',
            self::Cash => '€ Cash',
            self::Fee => '◷ Fee',
            self::Refund => '↺ Refund',
            self::Unknown => '? Unknown',
        };
    }

    /**
     * CSS modifier appended to the `.ptype-chip` class to drive the
     * sketch-locked color mapping (PIN-violet, online-blue,
     * transfer-muted, dd-amber, cash-dark-amber, plus the fee / refund /
     * unknown extensions). Returned as a bare modifier (e.g. `pin`,
     * `dd`) so consumers build the full class via `.ptype-chip.{class}`.
     */
    public function chipClass(): string
    {
        return match ($this) {
            self::Pin => 'pin',
            self::Online => 'online',
            self::Transfer => 'transfer',
            self::DirectDebit => 'dd',
            self::Cash => 'cash',
            self::Fee => 'fee',
            self::Refund => 'refund',
            self::Unknown => 'unknown',
        };
    }
}
