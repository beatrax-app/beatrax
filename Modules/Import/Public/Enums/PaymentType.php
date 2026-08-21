<?php

declare(strict_types=1);

namespace Modules\Import\Public\Enums;

use Modules\Core\Public\Support\Lang;

// A database trigger rejects any transactions.payment_type outside these
// backing values, so a new case needs a migration to widen the trigger first.
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

    // In the enum so the preview's legend bar and every row's chip cannot
    // drift apart. The glyph is language-independent; only the word beside
    // it comes from the lang file.
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
