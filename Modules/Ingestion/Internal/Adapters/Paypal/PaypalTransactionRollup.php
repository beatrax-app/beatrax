<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Paypal;

use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ingestion\Public\Paypal\PaypalCsvEventTypeMap;

/**
 * Three-pass Transaction-ID / Reference-Txn-ID rollup walker for PayPal
 * Activity Download rows.
 *
 * PayPal's CSV is an EVENT log, not a transaction log: a single logical
 * payment can produce up to four rows (parent + funding-source + EUR
 * currency-conversion leg + foreign currency-conversion leg). This
 * walker folds those event rows into one canonical
 * `SourceTransactionDto` per logical payment.
 *
 * Three passes:
 *
 *   1. Index rows by `Transaction ID`. Drop rows whose event type
 *      classifies as `'skip'` (Hold / Authorization / Reserve /
 *      Reversal); count them via `skippedHoldCount()` so the wizard
 *      can surface the count without re-deriving it.
 *
 *   2. Partition surviving rows into parents vs children by
 *      `Reference Txn ID`:
 *        - parent: `Reference Txn ID` is empty, self-referential, or
 *          points at a Transaction ID NOT in this file (cross-period
 *          billing-agreement parent — the row IS the parent inside
 *          this window)
 *        - child: `Reference Txn ID` points at another row's
 *          Transaction ID inside this file
 *      A row classified as `'child-fee'` or `'child-fx'` whose
 *      Reference Txn ID points outside the file becomes a standalone
 *      parent AND increments `orphanChildCount()` — that surface is
 *      audit-only.
 *
 *   3. For each parent, fold its children into ONE
 *      `SourceTransactionDto`:
 *        - `amountMinor` / `currency` = native (non-EUR) leg of any
 *          `child-fx` pair, else the parent's Gross
 *        - `settledAmountMinor` / `settledCurrency` = EUR leg of any
 *          `child-fx` pair, else null
 *        - `fxRateUsed` = null (NormalizeStage derives it later from
 *          the native/settled pair per Phase 3 D-39)
 *        - `rawPayload` = `{ format: 'paypal-csv', events: [{ type,
 *          row }, ...] }` with parent first then children in CSV order
 *        - `sourceRef` = parent's Transaction ID (D-64)
 *        - `sourceRowIndex` = monotonically increasing across the
 *          rolled-up output (0, 1, 2, ...), NOT the raw CSV row index
 *
 * Pitfall 2 safety net (FX direction): the walker identifies the
 * foreign leg by `Currency != 'EUR'`, NEVER by row order. Both legs
 * of a PayPal currency-conversion pair are labelled with the same
 * `Algemene valutaomrekening` event type and share a `Reference Txn ID`,
 * but only the non-EUR leg carries the native-currency amount.
 */
final class PaypalTransactionRollup
{
    private int $skippedHoldCount = 0;

    private int $orphanChildCount = 0;

    public function __construct(
        private readonly PaypalCsvEventTypeMap $events,
        private readonly PaypalAmountParser $amounts,
        private readonly PaypalDateParser $dates,
        private readonly PaypalCsvColumnMap $columns,
    ) {}

    /**
     * Folds the flat row list into one canonical DTO per logical
     * payment.
     *
     * @param  list<array<string, string>>  $rawRows  one entry per CSV record
     * @return list<SourceTransactionDto>
     */
    public function rollup(array $rawRows, string $language): array
    {
        $this->skippedHoldCount = 0;
        $this->orphanChildCount = 0;

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

        // Pass 2: partition into parents vs children. A row is a child
        // ONLY when its event type is classified as 'child-fee' or
        // 'child-fx' AND its Reference Txn ID points at another row in
        // this file. Otherwise it is a parent.
        //
        // Orphan child = classified as child-* AND RefId points outside
        // this file. These are promoted to standalone parents and
        // counted via orphanChildCount() for audit reporting.
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

        // Pass 3: fold each parent + its inside-file children into ONE
        // SourceTransactionDto.
        /** @var list<SourceTransactionDto> $rolledUp */
        $rolledUp = [];
        $canonicalIndex = 0;

        foreach ($parents as $parentRow) {
            $parentTxnId = $this->columns->value('transactionId', $language, $parentRow) ?? '';
            $children = $childrenByParent[$parentTxnId] ?? [];

            $rolledUp[] = $this->buildDto($parentRow, $children, $language, $canonicalIndex);
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

    /**
     * Builds the canonical DTO for one parent + its children.
     *
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

        // FX-direction safety net (Pitfall 2): scan child-fx siblings
        // and identify the foreign leg by Currency != 'EUR', NOT by row
        // order. The walker tolerates both orientations:
        //   - parent native foreign + child EUR settled
        //   - parent EUR settled + child non-EUR native (the empirical
        //     PayPal shape stamps Bruto on the parent with foreign
        //     currency, so the second orientation is unusual but covered
        //     defensively).
        foreach ($children as $childRow) {
            $childEventType = $this->columns->value('type', $language, $childRow) ?? '';
            $childAction = $this->events->classify($childEventType, $language);

            if ($childAction !== 'child-fx') {
                continue;
            }

            $childCurrency = $this->columns->value('currency', $language, $childRow) ?? 'EUR';
            $childGross = $this->columns->value('gross', $language, $childRow) ?? '0,00';
            $childAmountMinor = $this->amounts->parseMinor($childGross);

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
            // Else: same-currency pair — degenerate; ignore.
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
            sourceRef: $parentTxnId = $this->columns->value('transactionId', $language, $parentRow),
            description: $description,
            rawPayload: [
                'format' => 'paypal-csv',
                'events' => $events,
            ],
            sourceRowIndex: $canonicalIndex,
            settledAmountMinor: $settledAmountMinor,
            settledCurrency: $settledCurrency,
            fxRateUsed: null,
        );
    }

    /**
     * Composes a "{event-type} / {counterparty}" description, falling
     * back gracefully when either token is empty.
     */
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
