<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Paypal;

use Carbon\CarbonImmutable;
use Generator;
use League\Csv\CharsetConverter;
use League\Csv\Reader;
use Modules\Ingestion\Internal\Exceptions\UnsupportedPaypalCsvLanguageException;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Contracts\NamesRowsItCouldNotRead;
use Modules\Ingestion\Public\Contracts\SourceAdapter;
use Modules\Ingestion\Public\Enums\SyntheticIban;
use Modules\Ingestion\Public\Services\HeaderSniffer;
use Modules\Ledger\Public\Dto\StatementSummaryData;

final class PaypalCsvAdapter implements NamesRowsItCouldNotRead, SourceAdapter
{
    // A PayPal wallet has no IBAN, so this literal stands in as the account
    // key; AccountResolver scopes by user, so it cannot collide.
    private const PAYPAL_OWN_IBAN = SyntheticIban::Paypal->value;

    private ?StatementSummaryData $lastStatementMetadata = null;

    /** @var list<int> */
    private array $lastUnreadableRowIndexes = [];

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

    /**
     * @return list<int>
     */
    public function unreadableRowIndexes(): array
    {
        return $this->lastUnreadableRowIndexes;
    }

    public function parse(string $localPath, AccountResolver $accounts): Generator
    {
        // Cleared before the sniff, not after: this adapter is a singleton, so
        // a run refused at the door would otherwise answer statementMetadata()
        // and unreadableRowIndexes() with the previous file's.
        $this->lastStatementMetadata = null;
        $this->lastUnreadableRowIndexes = [];

        $this->sniffer->sniff($localPath, PaypalCsvLanguageProfile::FORMAT);

        $reader = Reader::createFromPath($localPath, 'r');
        $reader->setDelimiter(PaypalCsvLanguageProfile::DELIMITER);
        $reader->setEscape('');
        $reader->setHeaderOffset(0);
        CharsetConverter::addTo($reader, PaypalCsvLanguageProfile::SOURCE_ENCODING, 'UTF-8');

        // array_values pins the list shape detect() expects; PHPStan widens
        // getHeader() to array<int|string, string>.
        $headerColumns = array_values($reader->getHeader());
        // The Activity Download CSV ships a BOM on the first header cell, and
        // CharsetConverter leaves it since the source encoding is already Unicode.
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

        // Buffered, not yielded: the rollup walker needs the full set to resolve
        // Reference-Txn-ID parent/child links before any DTO can be emitted.
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

        $rolledUp = $this->rollup->rollup($rawRows, $language);
        $this->lastUnreadableRowIndexes = $this->rollup->unreadableRowIndexes();

        /** @var list<CarbonImmutable> $bookedDates */
        $bookedDates = [];
        /** @var array<string, int> $netByCurrency */
        $netByCurrency = [];
        $count = 0;
        foreach ($rolledUp as $dto) {
            yield $dto;
            $bookedDates[] = $dto->bookedAt;
            // The settled leg, which is what the wallet moved by: a USD row's
            // native minor units added into a euro total is arithmetic across
            // two denominations, and it is the figure /reconcile targets.
            $currency = $dto->settledCurrency ?? $dto->currency;
            $netByCurrency[$currency] = ($netByCurrency[$currency] ?? 0)
                + ($dto->settledAmountMinor ?? $dto->amountMinor);
            $count++;
        }

        $this->lastStatementMetadata = $this->buildStatementMetadata(
            bookedDates: $bookedDates,
            netByCurrency: $netByCurrency,
            entryCount: $count,
            language: $language,
        );
    }

    // PayPal ships no opening/closing balance rows, so it is the one format here
    // whose closing balance is summed rather than read. The walker counters in
    // $extras carry the rest of this format's audit signal.
    /**
     * @param  list<CarbonImmutable>  $bookedDates
     * @param  array<string, int>  $netByCurrency
     */
    private function buildStatementMetadata(
        array $bookedDates,
        array $netByCurrency,
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

        $unreadableRowCount = count($this->lastUnreadableRowIndexes);
        if ($unreadableRowCount > 0) {
            $extras['unreadableRowCount'] = $unreadableRowCount;
        }

        // No single currency, no balance: /reconcile reads this as a target and
        // asks the reader to close any gap by toggling rows, so one that no row
        // can close is worse than none at all. A row that could not be read is
        // the same case: summed over the rest, the total moves with the loss.
        $currency = count($netByCurrency) === 1 && $unreadableRowCount === 0
            ? array_key_first($netByCurrency)
            : null;

        return new StatementSummaryData(
            importRunId: 0,
            accountId: 0,
            ibanOwner: self::PAYPAL_OWN_IBAN,
            statementNumber: null,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            openingBalanceMinor: $currency === null ? null : 0,
            openingBalanceCurrency: $currency,
            openingBalanceDate: $periodStart,
            closingBalanceMinor: $currency === null ? null : $netByCurrency[$currency],
            closingBalanceCurrency: $currency,
            closingBalanceDate: $periodEnd,
            entryCount: $entryCount,
            extras: $extras,
        );
    }
}
