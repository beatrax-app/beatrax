<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Paypal;

use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ingestion\Public\Exceptions\InvalidAmountException;
use Modules\Ingestion\Public\Exceptions\InvalidDateException;
use Modules\Ingestion\Public\Paypal\PaypalCsvEventTypeMap;

/**
 * @link ../../../../../.docs/features/ingestion/architecture.md
 */
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

        // Pass 1: drop skipped event types, index surviving rows by
        // their Transaction ID, and record per-row classification so
        // the subsequent passes don't re-call classify() repeatedly.
        /** @var list<array{row: array<string, string>, action: string, txnId: string}> $surviving */
        $surviving = [];
        /** @var array<string, bool> $byTxnId */
        $byTxnId = [];

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

            $txnId = $this->columns->value('transactionId', $language, $row) ?? '';
            $surviving[] = ['row' => $row, 'action' => $action, 'txnId' => $txnId];
            if ($txnId !== '') {
                $byTxnId[$txnId] = true;
            }
        }

        // Pass 2: a row is a child only when classified 'child-fee' or
        // 'child-fx' AND its Reference Txn ID points at another row in this
        // file; an orphan child (RefId points outside the file) is promoted
        // to a standalone parent and counted via orphanChildCount().
        /** @var array<string, list<array<string, string>>> $childrenByParent */
        $childrenByParent = [];
        /** @var list<array<string, string>> $parents */
        $parents = [];

        foreach ($surviving as $entry) {
            $row = $entry['row'];
            $action = $entry['action'];
            $txnId = $entry['txnId'];
            $refId = $this->columns->value('referenceTxnId', $language, $row) ?? '';

            $isChildAction = $action === 'child-fee' || $action === 'child-fx';
            $pointsAtInsideRow = $refId !== '' && $refId !== $txnId && isset($byTxnId[$refId]);

            if ($isChildAction) {
                if ($pointsAtInsideRow) {
                    $childrenByParent[$refId][] = $row;

                    continue;
                }

                // Orphan child: parent absent from this report window.
                // Promote to standalone parent so the row is not lost.
                $this->orphanChildCount++;
            }

            $parents[] = $row;
        }

        // Pass 3: fold each parent + its children into one DTO. A malformed
        // parent amount drops the whole logical-payment group and bumps
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

        // FX-direction safety net: identify the foreign leg by Currency !=
        // 'EUR', never by row order — the walker tolerates both the
        // typical (parent native + child EUR settled) and swapped
        // orientation defensively.
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
                // A malformed child amount cell drops just the FX child —
                // the parent still emits a canonical DTO without the FX
                // pair filled in. Counted via skippedMalformedRowCount so
                // the audit signal stays visible.
                $this->skippedMalformedRowCount++;

                continue;
            }

            if ($childCurrency === 'EUR' && $nativeCurrency !== 'EUR') {
                // child = EUR settled leg; parent already holds the
                // foreign-currency native amount.
                $settledAmountMinor = $childAmountMinor;
                $settledCurrency = $childCurrency;
            } elseif ($childCurrency !== 'EUR' && $nativeCurrency === 'EUR') {
                // Swap: parent's Gross is actually the EUR settled leg;
                // child holds the foreign native leg.
                $settledAmountMinor = $nativeAmountMinor;
                $settledCurrency = $nativeCurrency;
                $nativeAmountMinor = $childAmountMinor;
                $nativeCurrency = $childCurrency;
            }
            // A same-currency pair is degenerate for FX purposes and is
            // left as-is (no settled leg populated).
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
