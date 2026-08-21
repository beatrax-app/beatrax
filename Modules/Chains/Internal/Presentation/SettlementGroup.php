<?php

declare(strict_types=1);

namespace Modules\Chains\Internal\Presentation;

use Carbon\CarbonImmutable;
use Modules\Chains\Public\Dto\ChainLinkRow;
use Modules\Ledger\Public\ValueObjects\Money;

// A chain is a fan-in: several purchases collected into one settlement, and the
// query returns one flat row per link. Grouping is what stops /chains rendering
// eight near-identical cards for one ICS collection.
final class SettlementGroup
{
    /**
     * @param  list<ChainLinkRow>  $legs  the payments funded by this settlement
     */
    private function __construct(
        public readonly int $transactionId,
        public readonly string $counterparty,
        public readonly ?string $counterpartySlug,
        public readonly Money $amount,
        public readonly CarbonImmutable $postedAt,
        public readonly string $kind,
        public readonly string $state,
        public readonly array $legs,
        public readonly Money $legTotal,
    ) {}

    // Grouping keys by settlement id and PHP preserves insertion order, so the
    // query's newest-first ordering survives into the returned groups.
    /**
     * @param  list<ChainLinkRow>  $rows
     * @return list<self>
     */
    public static function fromRows(array $rows): array
    {
        /** @var array<int, list<ChainLinkRow>> $byTransaction */
        $byTransaction = [];
        foreach ($rows as $row) {
            $byTransaction[$row->toTransactionId][] = $row;
        }

        $groups = [];
        foreach ($byTransaction as $transactionId => $legs) {
            $first = $legs[0];
            $groups[] = new self(
                transactionId: $transactionId,
                counterparty: $first->toCounterparty,
                counterpartySlug: $first->toCounterpartySlug,
                amount: $first->toAmount,
                postedAt: $first->toPostedAt,
                kind: $first->kind,
                state: self::worstState($legs),
                legs: $legs,
                legTotal: self::sumOfLegs($legs),
            );
        }

        return $groups;
    }

    // There is deliberately no "unaccounted for" figure: linked purchases
    // routinely exceed the charge they settle into, because the resolver matches
    // across statement cycles, so settlement-minus-legs would render an
    // overshoot as a shortfall.

    // A settlement is only as settled as its least-settled leg: one unreviewed
    // candidate among seven confirmed links still needs a person to look.
    /**
     * @param  list<ChainLinkRow>  $legs
     */
    private static function worstState(array $legs): string
    {
        foreach ($legs as $leg) {
            if ($leg->state === 'candidate') {
                return 'candidate';
            }
        }

        return $legs[0]->state;
    }

    /**
     * @param  list<ChainLinkRow>  $legs
     */
    private static function sumOfLegs(array $legs): Money
    {
        $total = Money::ofMinor(0, $legs[0]->fromAmount->currency());
        foreach ($legs as $leg) {
            $total = $total->plus($leg->fromAmount);
        }

        return $total;
    }
}
