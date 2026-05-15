<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Paypal;

use Carbon\CarbonImmutable;
use Generator;
use League\Csv\CharsetConverter;
use League\Csv\Reader;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Contracts\SourceAdapter;
use Modules\Ingestion\Public\Exceptions\UnsupportedPaypalCsvLanguageException;
use Modules\Ingestion\Public\Services\HeaderSniffer;
use Modules\Ledger\Public\Dto\StatementSummaryData;

/**
 * Streaming parser for the PayPal "Activity Download" CSV export.
 *
 * The adapter wires the language profile + event-type map + locale-
 * specific parsers into a single Generator that yields one canonical
 * `SourceTransactionDto` per logical PayPal payment. PayPal CSV is an
 * event log (parent + fee + currency-conversion + funding-source
 * siblings), not a transaction log; the rollup walker folds those event
 * rows into the per-payment canonical shape.
 *
 * Pipeline:
 *
 *   1. HeaderSniffer rejects non-CSV uploads + headers that don't match
 *      any registered language profile before any cells are parsed.
 *   2. league/csv reads the file streaming, with CharsetConverter
 *      normalising any byte-order-mark or non-UTF-8 cells (the empirical
 *      fixture is UTF-8 with a leading BOM).
 *   3. PaypalCsvLanguageProfile::detect() inspects the parsed header
 *      tokens against the registered signature set; on miss the adapter
 *      raises UnsupportedPaypalCsvLanguageException (typed-exception
 *      surface so the wizard renders a user-facing message).
 *   4. PaypalTransactionRollup folds raw rows into canonical DTOs; the
 *      walker exposes hold-skip count + orphan-child count for audit.
 *   5. After iteration, statementMetadata() exposes a StatementSummary
 *      row carrying the period dates (min/max bookedAt), net-sum total,
 *      and walker counters (skippedHoldCount, orphanChildCount).
 *
 * Synthetic-IBAN account modeling: every PayPal row carries
 * `ownIban = 'PAYPAL'`. The AccountResolver scopes lookups by
 * (iban, user_id), so one literal works across all users.
 *
 * The PayPal Activity Download does not ship explicit opening / closing
 * balance rows — the per-event `Saldo` cell resets to zero after every
 * funding sweep — so no opening-vs-closing reconciliation gate is
 * computed for this adapter. The walker counters above carry the
 * audit signal instead.
 */
final class PaypalCsvAdapter implements SourceAdapter
{
    /**
     * Synthetic own-IBAN literal for PayPal accounts. PayPal wallets
     * have no real IBAN; the AccountResolver scopes lookups by
     * `(iban, user_id)`, so a single per-instance literal is
     * unambiguous regardless of the user.
     */
    private const PAYPAL_OWN_IBAN = 'PAYPAL';

    private ?StatementSummaryData $lastStatementMetadata = null;

    public function __construct(
        private readonly HeaderSniffer $sniffer,
        private readonly PaypalTransactionRollup $rollup,
    ) {}

    public function format(): string
    {
        return PaypalCsvLanguageProfile::FORMAT;
    }

    public function statementMetadata(): ?StatementSummaryData
    {
        return $this->lastStatementMetadata;
    }

    public function parse(string $localPath, AccountResolver $accounts): Generator
    {
        $this->sniffer->sniff($localPath, PaypalCsvLanguageProfile::FORMAT);
        $this->lastStatementMetadata = null;

        $reader = Reader::createFromPath($localPath, 'r');
        $reader->setDelimiter(PaypalCsvLanguageProfile::DELIMITER);
        $reader->setEscape('');
        $reader->setHeaderOffset(0);
        CharsetConverter::addTo($reader, PaypalCsvLanguageProfile::SOURCE_ENCODING, 'UTF-8');

        // Coerce the header to a positional list of strings — league/csv
        // returns an array<int, string> but PHPStan widens it to
        // array<int|string, string> by default. Explicit array_values
        // pins the list shape PaypalCsvLanguageProfile::detect expects.
        $headerColumns = array_values($reader->getHeader());
        // Strip a leading UTF-8 BOM from the first header cell — the
        // empirical Activity Download CSV ships one. CharsetConverter
        // does NOT strip it because the source encoding is already
        // UTF-8.
        if (isset($headerColumns[0])) {
            $headerColumns[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headerColumns[0]) ?? $headerColumns[0];
        }

        $languageProfile = PaypalCsvLanguageProfile::detect($headerColumns);
        if ($languageProfile === null) {
            throw new UnsupportedPaypalCsvLanguageException(
                'PayPal CSV header tokens did not match any registered language profile. '
                .'Supported locales: '.implode(', ', PaypalCsvLanguageProfile::supported())
                .'. If your account exports in a different language, file an issue with the redacted CSV.'
            );
        }
        $language = $languageProfile->detected();

        // Buffer all raw rows for the rollup walker (D-61). PayPal's
        // per-event shape means we cannot yield row-by-row from
        // league/csv directly — the walker needs the full set to
        // resolve Reference-Txn-ID parent/child links before emitting
        // canonical DTOs.
        // Under setHeaderOffset(0) league/csv 9.x yields records as
        // string-keyed arrays whose values are string|null (null for
        // ragged short rows). Coerce null cells to empty string so the
        // column map can resolve uniformly.
        /** @var list<array<string, string>> $rawRows */
        $rawRows = [];
        foreach ($reader->getRecords() as $record) {
            /** @var array<string, string> $assoc */
            $assoc = [];
            /** @var array<string, string|null> $record */
            foreach ($record as $key => $cell) {
                $assoc[$key] = $cell ?? '';
            }
            $rawRows[] = $assoc;
        }

        $accounts->resolve(self::PAYPAL_OWN_IBAN);

        $rolledUp = $this->rollup->rollup($rawRows, $language);

        /** @var list<CarbonImmutable> $bookedDates */
        $bookedDates = [];
        $netSumMinor = 0;
        $count = 0;
        foreach ($rolledUp as $dto) {
            yield $dto;
            $bookedDates[] = $dto->bookedAt;
            $netSumMinor += $dto->amountMinor;
            $count++;
        }

        $this->lastStatementMetadata = $this->buildStatementMetadata(
            bookedDates: $bookedDates,
            netSumMinor: $netSumMinor,
            entryCount: $count,
            language: $language,
        );
    }

    /**
     * Assembles the statement-level metadata DTO.
     *
     * The PayPal Activity Download has no explicit opening / closing
     * balance rows, so the summary records `closing = sum(net)` and
     * `opening = 0` as bookkeeping placeholders only — no reconciliation
     * gate is published in `extras`. The walker counters
     * (`skippedHoldCount`, `orphanChildCount`, optionally
     * `skippedMalformedRowCount`) carry the audit signal for this format.
     *
     * @param  list<CarbonImmutable>  $bookedDates
     */
    private function buildStatementMetadata(
        array $bookedDates,
        int $netSumMinor,
        int $entryCount,
        string $language,
    ): StatementSummaryData {
        if ($bookedDates !== []) {
            $sorted = $bookedDates;
            usort($sorted, static fn (CarbonImmutable $a, CarbonImmutable $b): int => $a <=> $b);
            $periodStart = $sorted[0];
            $periodEnd = $sorted[count($sorted) - 1];
        } else {
            $periodStart = null;
            $periodEnd = null;
        }

        $extras = [
            'language' => $language,
            'skippedHoldCount' => $this->rollup->skippedHoldCount(),
            'orphanChildCount' => $this->rollup->orphanChildCount(),
        ];

        $skippedMalformedRowCount = $this->rollup->skippedMalformedRowCount();
        if ($skippedMalformedRowCount > 0) {
            $extras['skippedMalformedRowCount'] = $skippedMalformedRowCount;
        }

        return new StatementSummaryData(
            importRunId: 0,
            accountId: 0,
            ibanOwner: self::PAYPAL_OWN_IBAN,
            statementNumber: null,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            openingBalanceMinor: 0,
            openingBalanceCurrency: 'EUR',
            openingBalanceDate: $periodStart,
            closingBalanceMinor: $netSumMinor,
            closingBalanceCurrency: 'EUR',
            closingBalanceDate: $periodEnd,
            entryCount: $entryCount,
            extras: $extras,
        );
    }
}
