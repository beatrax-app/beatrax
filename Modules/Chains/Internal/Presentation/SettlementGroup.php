<?php

declare(strict_types=1);

namespace Modules\Chains\Internal\Presentation;

use Carbon\CarbonImmutable;
use Modules\Chains\Internal\Dto\SettlementTotals;
use Modules\Chains\Public\Dto\ChainLinkRow;
use Modules\Chains\Public\Enums\ChainLinkKind;
use Modules\Chains\Public\Enums\ChainLinkState;
use Modules\Ledger\Public\ValueObjects\Money;

// A chain is a fan-in: several purchases collected into one settlement, and the
// query returns one flat row per link. Grouping is what stops /chains rendering
// eight near-identical cards for one ICS collection.
final readonly class SettlementGroup
{
    /**
     * @param  list<SettlementLeg>  $legs  the payments this card lists, capped by the query
     * @param  list<Money>  $legTotals  one total per currency over EVERY leg, listed or not
     * @param  int  $legCount  how many legs the settlement has, listed or not
     */
    private function __construct(
        public int $transactionId,
        public string $counterparty,
        public ?string $counterpartySlug,
        public Money $amount,
        public CarbonImmutable $postedAt,
        public string $kind,
        public string $state,
        public array $legs,
        public array $legTotals,
        public int $legCount,
    ) {}

    // Grouping keys by settlement id and PHP preserves insertion order, so the
    // query's newest-first ordering survives into the returned groups.
    /**
     * @param  list<ChainLinkRow>  $rows
     * @param  list<SettlementTotals>  $totals  one entry per settlement, over every leg it has
     * @return list<self>
     */
    public static function fromRows(array $rows, array $totals): array
    {
        /** @var array<int, SettlementTotals> $totalsBySettlement */
        $totalsBySettlement = [];
        foreach ($totals as $entry) {
            $totalsBySettlement[$entry->settlementTransactionId] = $entry;
        }

        /** @var array<int, list<ChainLinkRow>> $byTransaction */
        $byTransaction = [];
        foreach ($rows as $row) {
            $byTransaction[self::settlementTransactionId($row)][] = $row;
        }

        $groups = [];
        foreach ($byTransaction as $transactionId => $rowsForSettlement) {
            $first = $rowsForSettlement[0];
            $legs = array_map(self::leg(...), $rowsForSettlement);
            $settlementTotals = $totalsBySettlement[$transactionId] ?? null;
            $groups[] = new self(
                transactionId: $transactionId,
                counterparty: self::fromSide($first) ? $first->fromCounterparty : $first->toCounterparty,
                counterpartySlug: self::fromSide($first) ? $first->fromCounterpartySlug : $first->toCounterpartySlug,
                amount: self::fromSide($first) ? $first->fromAmount : $first->toAmount,
                postedAt: self::fromSide($first) ? $first->fromPostedAt : $first->toPostedAt,
                kind: $first->kind,
                state: self::worstState($legs, $settlementTotals),
                legs: $legs,
                legTotals: $settlementTotals->totals ?? self::totalsByCurrency($legs),
                legCount: $settlementTotals->legCount ?? count($legs),
            );
        }

        return $groups;
    }

    private static function fromSide(ChainLinkRow $row): bool
    {
        return ChainLinkKind::tryFrom($row->kind)?->settlementIsFromSide() ?? false;
    }

    private static function settlementTransactionId(ChainLinkRow $row): int
    {
        return self::fromSide($row) ? $row->fromTransactionId : $row->toTransactionId;
    }

    private static function leg(ChainLinkRow $row): SettlementLeg
    {
        $settlementIsFrom = self::fromSide($row);

        return new SettlementLeg(
            chainLinkId: $row->chainLinkId,
            transactionId: $settlementIsFrom ? $row->toTransactionId : $row->fromTransactionId,
            counterparty: $settlementIsFrom ? $row->toCounterparty : $row->fromCounterparty,
            counterpartySlug: $settlementIsFrom ? $row->toCounterpartySlug : $row->fromCounterpartySlug,
            amount: $settlementIsFrom ? $row->toAmount : $row->fromAmount,
            postedAt: $settlementIsFrom ? $row->toPostedAt : $row->fromPostedAt,
            state: $row->state,
        );
    }

    // There is deliberately no "unaccounted for" figure: linked purchases
    // routinely exceed the charge they settle into, because the resolver matches
    // across statement cycles, so settlement-minus-legs would render an
    // overshoot as a shortfall.

    // A settlement is only as settled as its least-settled leg: one unreviewed
    // candidate among seven confirmed links still needs a person to look — and
    // the one that needs looking at need not be among the legs the card lists,
    // so the aggregate answers first where it has one.
    /**
     * @param  list<SettlementLeg>  $legs
     */
    private static function worstState(array $legs, ?SettlementTotals $totals): string
    {
        if ($totals?->hasCandidateLeg === true) {
            return ChainLinkState::Candidate->value;
        }

        foreach ($legs as $leg) {
            if ($leg->state === ChainLinkState::Candidate->value) {
                return ChainLinkState::Candidate->value;
            }
        }

        return $legs[0]->state;
    }

    // One total per currency rather than one total: Money::plus() throws on a
    // currency mismatch, and a single foreign leg — a PayPal payment settled in
    // USD — took the whole /chains page down with it. Reached only when no
    // aggregate names this settlement, so it counts the listed legs alone.
    /**
     * @param  list<SettlementLeg>  $legs
     * @return list<Money>
     */
    private static function totalsByCurrency(array $legs): array
    {
        /** @var array<string, Money> $totals */
        $totals = [];
        foreach ($legs as $leg) {
            $currency = $leg->amount->currency();
            $totals[$currency] = isset($totals[$currency])
                ? $totals[$currency]->plus($leg->amount)
                : $leg->amount;
        }

        return array_values($totals);
    }
}
