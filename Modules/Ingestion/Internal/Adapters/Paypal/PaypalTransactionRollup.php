<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Paypal;

use Modules\Ingestion\Internal\Exceptions\InvalidAmountException;
use Modules\Ingestion\Internal\Exceptions\InvalidDateException;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ingestion\Public\Enums\SyntheticIban;
use Modules\Ingestion\Public\Paypal\PaypalCsvEventTypeMap;
use Modules\Ingestion\Public\Paypal\PaypalEventAction;
use Modules\Ledger\Public\Enums\Currency;

final class PaypalTransactionRollup
{
    private int $skippedHoldCount = 0;

    private int $orphanChildCount = 0;

    /** @var list<int> */
    private array $unreadableRowIndexes = [];

    private int $unreadableChildLegCount = 0;

    private ?string $balanceCurrency = null;

    public function __construct(
        private readonly PaypalCsvEventTypeMap $events,
        private readonly PaypalAmountParser $amounts,
        private readonly PaypalDateParser $dates,
        private readonly PaypalCsvColumnMap $columns,
    ) {}

    /**
     * @param  list<array<string, string>>  $rawRows  one entry per CSV record
     * @return list<SourceTransactionDto>
     */
    public function rollup(array $rawRows, string $language): array
    {
        $this->skippedHoldCount = 0;
        $this->orphanChildCount = 0;
        $this->unreadableRowIndexes = [];
        $this->unreadableChildLegCount = 0;

        $surviving = $this->filterSurviving($rawRows, $language);
        [$parents, $childrenByParent] = $this->partitionParents($surviving, $language);
        $this->balanceCurrency = $this->deriveBalanceCurrency($parents, $childrenByParent, $language);

        // A malformed parent amount drops the whole logical-payment group rather
        // than raising, so one unreadable cell does not refuse the export. The
        // group keeps its place in the sequence: closed over, the indexes read
        // as a whole file and the preview has nothing to hang the loss on.
        /** @var list<SourceTransactionDto> $rolledUp */
        $rolledUp = [];
        $canonicalIndex = 0;

        foreach ($parents as $parentRow) {
            $parentTxnId = $this->columns->value('transactionId', $language, $parentRow) ?? '';
            $children = $childrenByParent[$parentTxnId] ?? [];

            // Both built before either is kept, so an unreadable fee cell drops
            // the payment it sits on instead of booking half a pair, and the
            // group still spends exactly the one index the loss is reported at.
            try {
                $payment = $this->buildDto($parentRow, $children, $language, $canonicalIndex);
                $fee = $this->buildFeeDto($parentRow, $language, $canonicalIndex + 1, $payment);
            } catch (InvalidAmountException|InvalidDateException) {
                $this->unreadableRowIndexes[] = $canonicalIndex;
                $canonicalIndex++;

                continue;
            }

            $rolledUp[] = $payment;
            $canonicalIndex++;

            if ($fee !== null) {
                $rolledUp[] = $fee;
                $canonicalIndex++;
            }
        }

        // A dropped conversion leg is discovered inside a payment that goes on
        // to emit, so its slot is taken after the payments rather than among
        // them; what matters is that the file accounts for it at all.
        for ($leg = 0; $leg < $this->unreadableChildLegCount; $leg++) {
            $this->unreadableRowIndexes[] = $canonicalIndex;
            $canonicalIndex++;
        }

        return $rolledUp;
    }

    /**
     * @param  list<array<string, string>>  $rawRows  one entry per CSV record
     * @return list<array{row: array<string, string>, action: PaypalEventAction, txnId: string}>
     */
    private function filterSurviving(array $rawRows, string $language): array
    {
        $surviving = [];

        foreach ($rawRows as $row) {
            $eventType = $this->columns->value('type', $language, $row) ?? '';
            if ($eventType === '') {
                continue;
            }

            $action = $this->events->classify($eventType, $language);
            if ($action === PaypalEventAction::Skip) {
                $this->skippedHoldCount++;

                continue;
            }

            $surviving[] = [
                'row' => $row,
                'action' => $action,
                'txnId' => $this->columns->value('transactionId', $language, $row) ?? '',
            ];
        }

        return $surviving;
    }

    /**
     * @param  list<array{row: array<string, string>, action: PaypalEventAction, txnId: string}>  $surviving
     * @return array{0: list<array<string, string>>, 1: array<string, list<array<string, string>>>}
     */
    private function partitionParents(array $surviving, string $language): array
    {
        /** @var array<string, bool> $byTxnId */
        $byTxnId = [];
        foreach ($surviving as $entry) {
            if ($entry['txnId'] !== '') {
                $byTxnId[$entry['txnId']] = true;
            }
        }

        /** @var array<string, list<array<string, string>>> $childrenByParent */
        $childrenByParent = [];
        /** @var list<array<string, string>> $parents */
        $parents = [];

        foreach ($surviving as $entry) {
            $row = $entry['row'];
            $refId = $this->columns->value('referenceTxnId', $language, $row) ?? '';

            $isChildAction = $entry['action']->isChild();
            $pointsAtInsideRow = $refId !== '' && $refId !== $entry['txnId'] && isset($byTxnId[$refId]);

            if ($isChildAction && $pointsAtInsideRow) {
                $childrenByParent[$refId][] = $row;

                continue;
            }

            if ($isChildAction) {
                $this->orphanChildCount++;
            }

            $parents[] = $row;
        }

        return [$parents, $childrenByParent];
    }

    // What the whole file says the balance is, for the payment a statement cut
    // at a month boundary left holding half a pair. Its own pair always wins;
    // this is only what stands in when the file kept the other half.
    /**
     * @param  list<array<string, string>>  $parents
     * @param  array<string, list<array<string, string>>>  $childrenByParent
     *
     * @link ../../../../../.docs/features/ingestion/a-paypal-wallet-that-is-not-in-euros.md
     */
    private function deriveBalanceCurrency(array $parents, array $childrenByParent, string $language): ?string
    {
        $stated = null;

        foreach ($parents as $parentRow) {
            $parentTxnId = $this->columns->value('transactionId', $language, $parentRow) ?? '';
            $balanceLeg = $this->pairedBalanceCurrency(
                $childrenByParent[$parentTxnId] ?? [],
                $language,
                $this->columns->value('currency', $language, $parentRow) ?? '',
            );

            // Two pairs naming different balances is a wallet the file does not
            // describe, and absorbing: no later pair agreeing undoes it.
            if ($balanceLeg !== null && $stated !== null && $stated !== $balanceLeg) {
                return null;
            }

            $stated ??= $balanceLeg;
        }

        return $stated;
    }

    // A conversion pair restates one payment in two denominations. The leg
    // carrying the parent's own currency is the parent restated, which leaves
    // the leg in the other currency as the balance the wallet moved by — an
    // answer read off the file, so every device reads the same one.
    /**
     * @param  list<array<string, string>>  $children
     */
    private function pairedBalanceCurrency(array $children, string $language, string $parentCurrency): ?string
    {
        $restatesParent = false;
        $otherCurrency = null;

        foreach ($children as $childRow) {
            $childEventType = $this->columns->value('type', $language, $childRow) ?? '';
            if ($this->events->classify($childEventType, $language) !== PaypalEventAction::ChildFx) {
                continue;
            }

            $childCurrency = $this->columns->value('currency', $language, $childRow) ?? '';
            $restatesParent = $restatesParent || $childCurrency === $parentCurrency;
            if ($childCurrency !== '' && $childCurrency !== $parentCurrency) {
                $otherCurrency = $childCurrency;
            }
        }

        return $restatesParent ? $otherCurrency : null;
    }

    public function skippedHoldCount(): int
    {
        return $this->skippedHoldCount;
    }

    public function orphanChildCount(): int
    {
        return $this->orphanChildCount;
    }

    /**
     * @return list<int>
     */
    public function unreadableRowIndexes(): array
    {
        return $this->unreadableRowIndexes;
    }

    // PayPal books each conversion leg in the direction ITS OWN balance moved,
    // so the balance leg funding an outgoing dollar payment is a credit. One
    // payment has one direction, the parent's; a leg lends the magnitude and
    // nothing else.

    // The balance leg is identified by its currency, never by row order: both
    // legs of a conversion pair share an event type and a Reference Txn ID.
    // Its own pair answers first, so a wallet really holding two balances gets
    // each payment against the one that paid for it.
    /**
     * @param  list<array<string, string>>  $children
     * @return array{0: int, 1: string, 2: ?int, 3: ?string}
     *
     * @phpstan-impure
     */
    private function withFxLegApplied(array $children, string $language, int $nativeAmountMinor, string $nativeCurrency): array
    {
        $parentAmountMinor = $nativeAmountMinor;
        $settledAmountMinor = null;
        $settledCurrency = null;
        $balanceCurrency = $this->pairedBalanceCurrency($children, $language, $nativeCurrency)
            ?? $this->balanceCurrency
            ?? $nativeCurrency;

        foreach ($children as $childRow) {
            $childEventType = $this->columns->value('type', $language, $childRow) ?? '';

            if ($this->events->classify($childEventType, $language) !== PaypalEventAction::ChildFx) {
                continue;
            }

            $childCurrency = $this->columns->value('currency', $language, $childRow) ?? $balanceCurrency;
            $childAmountMinor = $this->childLegAmount($childRow, $language, $childCurrency);

            if ($childAmountMinor === null) {
                continue;
            }

            if ($childCurrency === $balanceCurrency && $nativeCurrency !== $balanceCurrency) {
                $settledAmountMinor = self::asParentDirected($parentAmountMinor, $childAmountMinor);
                $settledCurrency = $childCurrency;
            } elseif ($childCurrency !== $balanceCurrency && $nativeCurrency === $balanceCurrency) {
                $settledAmountMinor = $nativeAmountMinor;
                $settledCurrency = $nativeCurrency;
                $nativeAmountMinor = self::asParentDirected($parentAmountMinor, $childAmountMinor);
                $nativeCurrency = $childCurrency;
            }
        }

        return [$nativeAmountMinor, $nativeCurrency, $settledAmountMinor, $settledCurrency];
    }

    // Null drops only the FX child; the parent still emits a DTO, with no FX
    // pair filled in. The leg is counted because the parent then buckets under
    // the wrong currency, and a statement whose legs disagree publishes no
    // balance at all.
    /**
     * @param  array<string, string>  $childRow
     *
     * @phpstan-impure
     */
    private function childLegAmount(array $childRow, string $language, string $childCurrency): ?int
    {
        $childGross = $this->columns->value('gross', $language, $childRow);

        try {
            if ($childGross === null) {
                throw new InvalidAmountException(
                    'PayPal conversion leg carries no gross-amount column; an absent column is not an amount of zero.',
                );
            }

            return $this->amounts->parseMinor($childGross, $childCurrency);
        } catch (InvalidAmountException) {
            $this->unreadableChildLegCount++;

            return null;
        }
    }

    /**
     * @param  array<string, string>  $parentRow
     * @param  list<array<string, string>>  $children
     *
     * @phpstan-impure
     */
    private function buildDto(array $parentRow, array $children, string $language, int $canonicalIndex): SourceTransactionDto
    {
        $parentGross = $this->columns->value('gross', $language, $parentRow);
        if ($parentGross === null) {
            throw new InvalidAmountException(
                'PayPal payment row carries no gross-amount column; an absent column is not an amount of zero.',
            );
        }

        $parentCurrency = $this->parentCurrency($parentRow, $language);

        [$nativeAmountMinor, $nativeCurrency, $settledAmountMinor, $settledCurrency] = $this->withFxLegApplied(
            $children,
            $language,
            $this->amounts->parseMinor($parentGross, $parentCurrency),
            $parentCurrency,
        );

        $bookedAt = $this->dates->parse($this->columns->value('date', $language, $parentRow) ?? '');

        $parentEventType = $this->columns->value('type', $language, $parentRow) ?? '';
        $counterpartyName = $this->columns->value('counterpartyName', $language, $parentRow);
        $counterpartyIban = $this->columns->value('counterpartyIban', $language, $parentRow);

        $events = [
            ['type' => $parentEventType, 'row' => $parentRow],
        ];
        foreach ($children as $childRow) {
            $events[] = [
                'type' => $this->columns->value('type', $language, $childRow) ?? '',
                'row' => $childRow,
            ];
        }

        $description = $this->formatDescription($parentEventType, $counterpartyName);

        return new SourceTransactionDto(
            bookedAt: $bookedAt,
            postedAt: $bookedAt,
            valueDate: $bookedAt,
            ownIban: SyntheticIban::Paypal->value,
            counterpartyIban: ($counterpartyIban === null || $counterpartyIban === '') ? null : $counterpartyIban,
            counterpartyName: ($counterpartyName === null || $counterpartyName === '') ? null : $counterpartyName,
            currency: $nativeCurrency,
            amountMinor: $nativeAmountMinor,
            sourceRef: $this->columns->value('transactionId', $language, $parentRow),
            description: $description,
            rawPayload: [
                'format' => 'paypal-csv',
                'language' => $language,
                'events' => $events,
            ],
            sourceRowIndex: $canonicalIndex,
            settledAmountMinor: $settledAmountMinor,
            settledCurrency: $settledCurrency,
        );
    }

    // PayPal's own arithmetic is Netto = Bruto + Kosten, and Netto is the step
    // its Saldo column takes. The payment row keeps Bruto, so the fee needs a
    // row of its own for the two to sum to what the wallet actually moved by —
    // and a row is the only shape a reader can categorise a fee in.
    /**
     * @param  array<string, string>  $parentRow
     *
     * @link ../../../../../.docs/features/ingestion/a-paypal-fee-is-a-row-of-its-own.md
     */
    private function buildFeeDto(array $parentRow, string $language, int $canonicalIndex, SourceTransactionDto $payment): ?SourceTransactionDto
    {
        $feeEventType = $this->columns->header('fee', $language);
        if ($feeEventType === null) {
            return null;
        }

        // The denomination of the row the cells sit on, never the payment's own:
        // a conversion fold can rewrite the payment's native leg to the currency
        // it settled in, and PayPal states none of these three figures in it —
        // it states them in the Valuta beside them.
        $feeCurrency = $this->parentCurrency($parentRow, $language);
        $feeMinor = $this->feeMinor($parentRow, $language, $feeCurrency);

        // Zero is what a wallet that only ever spends reads on every row, and a
        // movement of nothing is not a movement. Null is a row that states no
        // fee anywhere the parser can reach.
        if ($feeMinor === null || $feeMinor === 0) {
            return null;
        }

        return new SourceTransactionDto(
            bookedAt: $payment->bookedAt,
            postedAt: $payment->postedAt,
            valueDate: $payment->valueDate,
            ownIban: $payment->ownIban,
            counterpartyIban: $payment->counterpartyIban,
            counterpartyName: $payment->counterpartyName,
            currency: $feeCurrency,
            // Read signed and never derived: PayPal writes the fee negative
            // when it takes one and positive when it gives one back, so a
            // refunded sale keeps its fee pointing the way the file points it.
            amountMinor: $feeMinor,
            // The reference of the PayPal transaction both rows came out of.
            // source_ref names a source event rather than a row — it is
            // deliberately absent from the dedup tuple — so the fee and the
            // payment it was charged on share one.
            sourceRef: $payment->sourceRef,
            description: $this->formatDescription($feeEventType, $payment->counterpartyName),
            rawPayload: [
                'format' => 'paypal-csv',
                'language' => $language,
                'events' => [['type' => $feeEventType, 'row' => $parentRow]],
                // Survives an enrichment overwriting source_ref, which is the
                // one thing that can break the link above.
                'fee_of' => $payment->sourceRef,
            ],
            sourceRowIndex: $canonicalIndex,
        );
    }

    // What the row says the wallet moved by, less what it says the payment was.
    // Netto is the figure Saldo steps by, so a pair anchored on it sums to the
    // movement whatever the fee column says — or fails to say, the three ways
    // it can: absent, blank, or reading zero beside a Netto that disagrees.
    /**
     * @param  array<string, string>  $parentRow
     *
     * @link ../../../../../.docs/features/ingestion/a-paypal-fee-is-a-row-of-its-own.md#the-fee-is-read-off-the-movement-not-off-the-fee-column
     */
    private function feeMinor(array $parentRow, string $language, string $currency): ?int
    {
        $net = $this->statedMinor('net', $parentRow, $language, $currency);
        $gross = $this->statedMinor('gross', $parentRow, $language, $currency);

        if ($net !== null && $gross !== null) {
            return $net - $gross;
        }

        // Nothing readable states the movement, so the labelled figure is the
        // only thing left to go on. An unreadable one still raises, because a
        // fee the file states and the parser cannot read is not a fee of zero.
        $fee = $this->columns->value('fee', $language, $parentRow);

        return ($fee === null || $fee === '') ? null : $this->amounts->parseMinor($fee, $currency);
    }

    // Absent, blank and unreadable all answer "this row does not state it" so
    // that an anchor the file spells wrongly falls back to the fee column
    // instead of dropping a payment the column could still have booked.
    /**
     * @param  array<string, string>  $parentRow
     */
    private function statedMinor(string $canonical, array $parentRow, string $language, string $currency): ?int
    {
        $cell = $this->columns->value($canonical, $language, $parentRow);
        if ($cell === null || $cell === '') {
            return null;
        }

        try {
            return $this->amounts->parseMinor($cell, $currency);
        } catch (InvalidAmountException) {
            return null;
        }
    }

    /**
     * @param  array<string, string>  $parentRow
     */
    private function parentCurrency(array $parentRow, string $language): string
    {
        return $this->columns->value('currency', $language, $parentRow)
            ?? $this->balanceCurrency
            ?? Currency::Eur->value;
    }

    private static function asParentDirected(int $parentAmountMinor, int $childAmountMinor): int
    {
        $magnitude = abs($childAmountMinor);

        return $parentAmountMinor < 0 ? -$magnitude : $magnitude;
    }

    private function formatDescription(string $eventType, ?string $counterpartyName): ?string
    {
        $tokens = [];
        if ($eventType !== '') {
            $tokens[] = $eventType;
        }
        if ($counterpartyName !== null && $counterpartyName !== '') {
            $tokens[] = $counterpartyName;
        }

        if ($tokens === []) {
            return null;
        }

        return implode(' / ', $tokens);
    }
}
