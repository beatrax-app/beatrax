<?php

declare(strict_types=1);

namespace Modules\Import\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * @link ../../../../.docs/features/import/an-account-is-denominated-by-its-statement.md
 */
final class UnknownIban extends Data
{
    /**
     * @param  string|null  $statementCurrency  The one currency every parsed row on this IBAN settled in, or null when they named more than one and the file therefore names no single denomination.
     */
    public function __construct(
        public readonly string $iban,
        public readonly ?string $seenCounterpartyName,
        public readonly ?string $statementCurrency = null,
    ) {}
}
