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

            try {
                $rolledUp[] = $this->buildDto($parentRow, $children, $language, $canonicalIndex);
            } catch (InvalidAmountException|InvalidDateException) {
                $this->unreadableRowIndexes[] = $canonicalIndex;
            }

            $canonicalIndex++;
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

        $parentCurrency = $this->columns->value('currency', $language, $parentRow) ?? Currency::Eur->value;

        $nativeAmountMinor = $this->amounts->parseMinor($parentGross, $parentCurrency);
        $nativeCurrency = $parentCurrency;
        $settledAmountMinor = null;
        $settledCurrency = null;

        // PayPal books each conversion leg in the direction ITS OWN balance
        // moved, so the euro leg funding an outgoing dollar payment is a credit.
        // One payment has one direction, the parent's; a leg lends the magnitude
        // and nothing else.
        $parentAmountMinor = $nativeAmountMinor;

        // The foreign leg is identified by its currency, never by row order: both
        // legs of a conversion pair share an event type and a Reference Txn ID.
        foreach ($children as $childRow) {
            $childEventType = $this->columns->value('type', $language, $childRow) ?? '';
            $childAction = $this->events->classify($childEventType, $language);

            if ($childAction !== PaypalEventAction::ChildFx) {
                continue;
            }

            $childCurrency = $this->columns->value('currency', $language, $childRow) ?? Currency::Eur->value;
            $childGross = $this->columns->value('gross', $language, $childRow);
            try {
                if ($childGross === null) {
                    throw new InvalidAmountException(
                        'PayPal conversion leg carries no gross-amount column; an absent column is not an amount of zero.',
                    );
                }

                $childAmountMinor = $this->amounts->parseMinor($childGross, $childCurrency);
            } catch (InvalidAmountException) {
                // A malformed child amount drops only the FX child; the parent still
                // emits a DTO, with no FX pair filled in. The leg is counted because
                // the parent then buckets under the wrong currency, and a statement
                // whose legs disagree publishes no balance at all.
                $this->unreadableChildLegCount++;

                continue;
            }

            if ($childCurrency === Currency::Eur->value && $nativeCurrency !== Currency::Eur->value) {
                $settledAmountMinor = self::asParentDirected($parentAmountMinor, $childAmountMinor);
                $settledCurrency = $childCurrency;
            } elseif ($childCurrency !== Currency::Eur->value && $nativeCurrency === Currency::Eur->value) {
                $settledAmountMinor = $nativeAmountMinor;
                $settledCurrency = $nativeCurrency;
                $nativeAmountMinor = self::asParentDirected($parentAmountMinor, $childAmountMinor);
                $nativeCurrency = $childCurrency;
            }
        }

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
