<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Aggregation;

final class SpendQueryFilters
{
    /**
     * @param  list<int>  $accountIds  restrict to these account ids (empty = no restriction); applied alongside the user_id guard, so a foreign id only narrows this user's own result, never widens it
     * @param  list<int>  $categoryIds  restrict to these category ids (empty = no restriction)
     * @param  list<int>  $counterpartyIds  restrict to these counterparty ids (empty = no restriction)
     * @param  ?int  $amountMinMinor  restrict to rows whose ABS(settled_amount_minor) >= this (null = no restriction)
     * @param  ?int  $amountMaxMinor  restrict to rows whose ABS(settled_amount_minor) <= this (null = no restriction)
     * @param  string  $amountDirection  'in' | 'out' | 'both' — restricts to settled_amount_minor > 0 / < 0 / no restriction
     */
    public function __construct(
        public readonly array $accountIds = [],
        public readonly array $categoryIds = [],
        public readonly array $counterpartyIds = [],
        public readonly ?int $amountMinMinor = null,
        public readonly ?int $amountMaxMinor = null,
        public readonly string $amountDirection = 'both',
    ) {}
}
