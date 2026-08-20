<?php

declare(strict_types=1);

namespace Modules\Import\Public\Enums;

use Modules\Core\Public\Support\Lang;

// A schema-level BEFORE INSERT/UPDATE trigger on transactions.payment_type
// rejects any value outside this set. `Unknown` is the safe default for
// every existing row at migration time and every import whose hinters
// can't agree on a value.
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

    // Kept inside the enum so the import preview's legend bar copy
    // matches the chip copy on every row (also rendered on /transactions
    // and /community/mystery-merchants). The glyph is the same in every
    // language; only the word beside it comes from the lang file.
    public function chipLabel(): string
    {
        $glyph = match ($this) {
            self::Pin => '⛁',
            self::Online => '⌘',
            self::Transfer => '↔',
            self::DirectDebit => '⤓',
            self::Cash => '€',
            self::Fee => '◷',
            self::Refund => '↺',
            self::Unknown => '?',
        };

        return $glyph.' '.Lang::get('import::payment_type.'.$this->value);
    }

    // Bare modifier (e.g. `pin`, `dd`); consumers build the full class
    // via `.ptype-chip.{class}` to drive the sketch-locked color mapping.
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
