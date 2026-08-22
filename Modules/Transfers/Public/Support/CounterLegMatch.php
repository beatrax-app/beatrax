<?php

declare(strict_types=1);

namespace Modules\Transfers\Public\Support;

use Modules\Ledger\Public\Enums\TransactionType;

// What the far leg has to look like. None of these carry a default, for the
// same reason the search itself refuses one: the two callers disagree about
// every one of them, and a default here would answer for the other caller a
// question it never asked.
final readonly class CounterLegMatch
{
    /**
     * @param  list<TransactionType>  $types
     */
    public function __construct(
        public int $accountId,
        public int $amountMinor,
        public array $types,
        public ?string $currency,
        public bool $unpairedOnly,
        // A row whose counterparty IBAN is its own account's would otherwise
        // answer its own search, which a zero-amount leg makes reachable.
        public ?int $excludeTransactionId,
    ) {}
}
