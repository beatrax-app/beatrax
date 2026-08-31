<?php

declare(strict_types=1);

namespace Modules\Import\Public\Services;

use Modules\Ledger\Public\Services\BaseCurrency;

/**
 * @link ../../../../.docs/features/import/an-account-is-denominated-by-its-statement.md
 */
final readonly class AccountDenomination
{
    public function __construct(
        private BaseCurrency $baseCurrency,
    ) {}

    // The reader's reporting currency is what is left when the file states
    // nothing, never the first answer: stamped first, a 229-row euro statement
    // opened an account labelled in yen because yen was what the reader
    // reported in.
    public function forStatement(?string $statementCurrency): string
    {
        return $statementCurrency === null || $statementCurrency === ''
            ? $this->baseCurrency->code()
            : $statementCurrency;
    }
}
