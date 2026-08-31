<?php

declare(strict_types=1);

namespace Modules\Import\Public\Enums;

// The closed vocabulary of transactions columns a receipt may disagree with a
// statement about. A field_name outside it never reaches an UPDATE column list,
// which is what stops a poisoned pending_enrichment_conflicts row naming one.
enum EnrichmentConflictField: string
{
    case CounterpartyName = 'counterparty_name';

    case Description = 'description';

    case Currency = 'currency';

    case AmountMinor = 'amount_minor';

    // Rewriting one of these without recomposing the stored digest leaves the
    // row unreachable by its own re-import, so the recompose travels in the
    // same statement. counterparty_name reaches the tuple through
    // counterparty_normalized rather than directly.
    public function isFingerprintInput(): bool
    {
        return match ($this) {
            self::CounterpartyName, self::Currency, self::AmountMinor => true,
            self::Description => false,
        };
    }
}
