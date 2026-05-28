<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Dto;

use Modules\Ledger\Public\ValueObjects\Money;
use Spatie\LaravelData\Data;

/**
 * Display-shape for one transaction row in the dashboard's "recent" panel and
 * in the `/transactions` list. The booked-at value is pre-formatted as
 * `d-m-Y` so the Blade template renders it without any further date helper.
 *
 * Holds a Money value object rather than a raw integer + currency string —
 * keeps the rendering layer one method call away from a typed amount and
 * matches the brick/money contract the rest of the codebase already uses.
 *
 * The optional `secondaryAmount` carries the settled-EUR amount when this
 * row is rendered in original-currency mode AND the native currency differs
 * from the settled currency — drives the two-line stack on the transactions
 * list (native primary line + settled secondary line). For EUR-native rows
 * it stays null so the rendering layer collapses to a single line; in
 * EUR-only mode (currency filter supplied) it is null on every row because
 * the primary amount already carries the settled-EUR pair.
 *
 * `counterpartySlug` carries the resolved-counterparty slug for the
 * transaction-row click-through anchor that routes to
 * `counterparties.profile`. NULL when the transaction has no resolved
 * counterparty (pre-resolver rows, pathological rows the resolver
 * couldn't materialise, or rows whose counterparty was pruned by the
 * garbage-collector job) — the Blade then renders the counterparty
 * name as plain text instead of a link.
 */
final class TransactionRowDto extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $bookedAt,
        public readonly ?string $counterpartyName,
        public readonly ?int $categoryId,
        public readonly ?string $categoryName,
        public readonly Money $amount,
        public readonly ?Money $secondaryAmount = null,
        public readonly ?string $counterpartySlug = null,
    ) {}
}
