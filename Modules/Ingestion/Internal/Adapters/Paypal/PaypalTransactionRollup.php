<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Paypal;

use Modules\Ingestion\Internal\Exceptions\InvalidAmountException;
use Modules\Ingestion\Internal\Exceptions\InvalidDateException;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ingestion\Public\Paypal\PaypalCsvEventTypeMap;

final class PaypalTransactionRollup
{
    private int $skippedHoldCount = 0;

    private int $orphanChildCount = 0;

    private int $skippedMalformedRowCount = 0;

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
        $this->skippedMalformedRowCount = 0;

        $surviving = $this->filterSurviving($rawRows, $language);
        [$parents, $childrenByParent] = $this->partitionParents($surviving, $language);

        // A malformed parent amount drops the whole logical-payment group and bumps
        // the malformed-row counter instead of raising.
        /** @var list<SourceTransactionDto> $rolledUp */
        $rolledUp = [];
        $canonicalIndex = 0;

        foreach ($parents as $parentRow) {
            $parentTxnId = $this->columns->value('transactionId', $language, $parentRow) ?? '';
            $children = $childrenByParent[$parentTxnId] ?? [];

            try {
                $rolledUp[] = $this->buildDto($parentRow, $children, $language, $canonicalIndex);
            } catch (InvalidAmountException|InvalidDateException) {
                $this->skippedMalformedRowCount++;

                continue;
            }
            $canonicalIndex++;
        }

        return $rolledUp;
    }

    /**
     * @param  list<array<string, string>>  $rawRows  one entry per CSV record
     * @return list<array{row: array<string, string>, action: string, txnId: string}>
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
            if ($action === 'skip') {
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
     * @param  list<array{row: array<string, string>, action: string, txnId: string}>  $surviving
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

            $isChildAction = $entry['action'] === 'child-fee' || $entry['action'] === 'child-fx';
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

    public function skippedMalformedRowCount(): int
    {
        return $this->skippedMalformedRowCount;
    }

    /**
     * @param  array<string, string>  $parentRow
     * @param  list<array<string, string>>  $children
     */
    private function buildDto(array $parentRow, array $children, string $language, int $canonicalIndex): SourceTransactionDto
    {
        $parentGross = $this->columns->value('gross', $language, $parentRow) ?? '0,00';
        $parentCurrency = $this->columns->value('currency', $language, $parentRow) ?? 'EUR';

        $nativeAmountMinor = $this->amounts->parseMinor($parentGross);
        $nativeCurrency = $parentCurrency;
        $settledAmountMinor = null;
        $settledCurrency = null;

        // The foreign leg is identified by Currency != 'EUR', never by row order: both
        // legs of a conversion pair share an event type and a Reference Txn ID.
        foreach ($children as $childRow) {
            $childEventType = $this->columns->value('type', $language, $childRow) ?? '';
            $childAction = $this->events->classify($childEventType, $language);

            if ($childAction !== 'child-fx') {
                continue;
            }

            $childCurrency = $this->columns->value('currency', $language, $childRow) ?? 'EUR';
            $childGross = $this->columns->value('gross', $language, $childRow) ?? '0,00';
            try {
                $childAmountMinor = $this->amounts->parseMinor($childGross);
            } catch (InvalidAmountException) {
                // A malformed child amount drops only the FX child; the parent still
                // emits a DTO, with no FX pair filled in.
                $this->skippedMalformedRowCount++;

                continue;
            }

            if ($childCurrency === 'EUR' && $nativeCurrency !== 'EUR') {
                $settledAmountMinor = $childAmountMinor;
                $settledCurrency = $childCurrency;
            } elseif ($childCurrency !== 'EUR' && $nativeCurrency === 'EUR') {
                $settledAmountMinor = $nativeAmountMinor;
                $settledCurrency = $nativeCurrency;
                $nativeAmountMinor = $childAmountMinor;
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
            ownIban: 'PAYPAL',
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
            fxRateUsed: null,
        );
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
