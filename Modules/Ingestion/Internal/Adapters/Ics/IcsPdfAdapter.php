<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Ics;

use Carbon\CarbonImmutable;
use Generator;
use Modules\Ingestion\Internal\Exceptions\IcsStatementDateUnreadableException;
use Modules\Ingestion\Internal\Exceptions\InvalidAmountException;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Contracts\SourceAdapter;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ingestion\Public\Enums\SyntheticIban;
use Modules\Ingestion\Public\Services\HeaderSniffer;
use Modules\Ledger\Public\Dto\StatementSummaryData;

/**
 * @link ../../../../../.docs/features/ingestion/ics-pdf-text-extraction.md
 */
final class IcsPdfAdapter implements SourceAdapter
{
    // A credit-card statement carries no IBAN, so the sentinel stands in as the
    // account key; AccountResolver scopes by user, so it cannot collide.
    private const ICS_OWN_IBAN = SyntheticIban::IcsCard->value;

    private const string SCRUB_LITERAL = '<discarded per security policy>';

    private const string AMOUNT_AF_BIJ_FRAGMENT = '€\s+([\d.,]+)\s+(?:Af|Bij)';

    private const string TRAILING_COUNTRY_CODE_REGEX = '/\s+[A-Z]{2}$/';

    // The statement header date and the payment deadline are both written this
    // way; spelled at each reader they were two lists of Dutch months.
    /** @var array<string, int> */
    private const array MONTH_ABBREV = [
        'jan' => 1, 'feb' => 2, 'mrt' => 3, 'apr' => 4,
        'mei' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8,
        'sep' => 9, 'okt' => 10, 'nov' => 11, 'dec' => 12,
    ];

    private ?StatementSummaryData $lastStatementMetadata = null;

    public function __construct(
        private readonly HeaderSniffer $sniffer,
        private readonly PdfTextExtractor $extractor,
        private readonly IcsAmountParser $amounts,
        private readonly IcsStatementHeader $header,
    ) {}

    public function format(): string
    {
        return IcsPdfHeaderProfile::FORMAT;
    }

    // The metadata is assembled in parse()'s terminator step, so a caller that
    // abandons the generator part-way still reads null here.
    public function statementMetadata(): ?StatementSummaryData
    {
        return $this->lastStatementMetadata;
    }

    public function parse(string $localPath, AccountResolver $accounts): Generator
    {
        // Cleared before the sniff, not after: this adapter is a singleton, so
        // a run refused at the door would otherwise answer statementMetadata()
        // with the previous file's statement.
        $this->lastStatementMetadata = null;

        $this->sniffer->sniff($localPath, IcsPdfHeaderProfile::FORMAT);

        $text = $this->extractor->extract($localPath);

        // stripPageNoise() strips the very header lines the statement date and
        // card number are read from, so the raw text is kept beside the cleaned.
        $rawText = $text;
        $cleaned = $this->stripPageNoise($text);

        // A row states a day and a month and never a year, so this one date is
        // the year of every transaction in the file. Stood in for by the wall
        // clock, an archived statement imported stamped into the current year.
        $statementDate = $this->header->statementDate($rawText);
        if (! $statementDate instanceof CarbonImmutable) {
            throw new IcsStatementDateUnreadableException;
        }

        $statementYear = $statementDate->year;
        $statementMonth = $statementDate->month;

        $cardLast4 = $this->header->cardLast4($rawText);

        $index = 0;
        /** @var list<CarbonImmutable> $postedDates */
        $postedDates = [];
        $entryCount = 0;
        $ownIban = $this->ownIban();

        foreach ($this->iterateTransactionBlocks($cleaned) as $block) {
            $dto = $this->buildDto($block, $index, $ownIban, $statementYear, $statementMonth);

            yield $dto;
            $postedDates[] = $dto->postedAt;
            $index++;
            $entryCount++;
        }

        $this->lastStatementMetadata = $this->buildStatementMetadata(
            rawText: $rawText,
            ownIban: $ownIban,
            postedDates: $postedDates,
            entryCount: $entryCount,
            cardLast4: $cardLast4,
        );
    }

    private function ownIban(): string
    {
        return self::ICS_OWN_IBAN;
    }

    private function stripPageNoise(string $text): string
    {
        foreach (IcsPdfExtractionMap::PAGE_NOISE_PATTERNS as $pattern) {
            $stripped = preg_replace($pattern, '', $text);
            if (is_string($stripped)) {
                $text = $stripped;
            }
        }

        return $text;
    }

    /**
     * @return Generator<int, string>
     */
    private function iterateTransactionBlocks(string $text): Generator
    {
        $lines = preg_split('/\r\n|\r|\n/', $text);
        if ($lines === false) {
            return;
        }

        $count = count($lines);
        $i = 0;
        while ($i < $count) {
            $trimmed = trim($lines[$i]);
            if ($trimmed === '' || ! $this->looksLikeTransactionRow($trimmed)) {
                $i++;

                continue;
            }

            $block = $trimmed;
            $next = $i + 1 < $count ? trim($lines[$i + 1]) : '';
            if (str_starts_with($next, IcsPdfExtractionMap::FX_LINE_ANCHOR)) {
                $block .= "\n".$next;
                $i++;
            }

            yield $block;
            $i++;
        }
    }

    private function looksLikeTransactionRow(string $line): bool
    {
        if (preg_match('/^\d{1,2}\s+(jan|feb|mrt|apr|mei|jun|jul|aug|sep|okt|nov|dec)\.?\s+/i', $line) !== 1) {
            return false;
        }

        return preg_match('/\s(Af|Bij)$/', $line) === 1;
    }

    private function buildDto(
        string $block,
        int $rowIndex,
        string $ownIban,
        int $statementYear,
        int $statementMonth,
    ): SourceTransactionDto {
        $lines = explode("\n", $block);
        $primary = $lines[0];
        $fxLine = $lines[1] ?? null;

        if (preg_match('/\s(Af|Bij)$/', $primary, $dirMatch) !== 1) {
            throw new InvalidAmountException(sprintf(
                'ICS transaction row does not end with Af/Bij marker: %s',
                $primary,
            ));
        }
        $direction = $dirMatch[1];
        $withoutDirection = trim(substr($primary, 0, strlen($primary) - strlen($dirMatch[0])));

        if (preg_match('/(.+?)\s+([\d.,]+)$/', $withoutDirection, $amountMatch) !== 1) {
            throw new InvalidAmountException(sprintf(
                'ICS transaction row missing settled amount: %s',
                $primary,
            ));
        }
        $rest = trim($amountMatch[1]);
        $settledRaw = $amountMatch[2];
        $settledMinor = $this->amounts->parse($settledRaw, IcsPdfHeaderProfile::STATEMENT_CURRENCY);
        if ($direction === 'Af') {
            $settledMinor = -$settledMinor;
        }

        $nativeAmountMinor = null;
        $nativeCurrency = null;
        if (preg_match('/(.+?)\s+([\d.,]+)\s+([A-Z]{3})$/', $rest, $fxMatch) === 1) {
            $rest = trim($fxMatch[1]);
            // The foreign column is read at ITS currency's scale, not the
            // euro column's: a yen has no minor unit, and the fixed hundredth
            // refused the row outright rather than reading it wrong.
            $nativeAmountMinor = $this->amounts->parse($fxMatch[2], $fxMatch[3]);
            if ($direction === 'Af') {
                $nativeAmountMinor = -$nativeAmountMinor;
            }
            $nativeCurrency = $fxMatch[3];
        }

        if (
            preg_match(
                '/^(\d{1,2})\s+([a-z]{3})\.?\s+(\d{1,2})\s+([a-z]{3})\.?\s+(.*)$/i',
                $rest,
                $dateMatch,
            ) !== 1
        ) {
            throw new InvalidAmountException(sprintf(
                'ICS transaction row missing date columns: %s',
                $primary,
            ));
        }

        $txMonth = self::MONTH_ABBREV[strtolower($dateMatch[2])] ?? null;
        $bookMonth = self::MONTH_ABBREV[strtolower($dateMatch[4])] ?? null;
        if ($txMonth === null || $bookMonth === null) {
            throw new InvalidAmountException(sprintf(
                'ICS transaction row has unknown month abbreviation: %s',
                $primary,
            ));
        }

        $txYear = self::yearForMonth($txMonth, $statementMonth, $statementYear);
        $bookYear = self::yearForMonth($bookMonth, $statementMonth, $statementYear);

        $bookedAt = CarbonImmutable::create($bookYear, $bookMonth, (int) $dateMatch[3], 0, 0, 0);
        if (! $bookedAt instanceof CarbonImmutable) {
            throw new InvalidAmountException(sprintf(
                'ICS transaction row has invalid booked date: %s',
                $primary,
            ));
        }
        $postedAt = CarbonImmutable::create($txYear, $txMonth, (int) $dateMatch[1], 0, 0, 0);
        if (! $postedAt instanceof CarbonImmutable) {
            throw new InvalidAmountException(sprintf(
                'ICS transaction row has invalid transaction date: %s',
                $primary,
            ));
        }
        $description = trim($dateMatch[5]);
        $counterpartyName = $this->extractCounterpartyName($description);

        $rawBlock = $this->scrubCardNumbers($block);

        return new SourceTransactionDto(
            bookedAt: $bookedAt->startOfDay(),
            postedAt: $postedAt->startOfDay(),
            valueDate: $bookedAt->startOfDay(),
            ownIban: $ownIban,
            counterpartyIban: null,
            counterpartyName: $counterpartyName,
            currency: $nativeCurrency ?? IcsPdfHeaderProfile::STATEMENT_CURRENCY,
            amountMinor: $nativeAmountMinor ?? $settledMinor,
            sourceRef: null,
            description: $description,
            rawPayload: [
                'format' => IcsPdfHeaderProfile::FORMAT,
                'extractedText' => $rawBlock,
                'fxRateDisplayed' => $this->parseDisplayedFxRate($fxLine),
            ],
            sourceRowIndex: $rowIndex,
            settledAmountMinor: $nativeCurrency === null ? null : $settledMinor,
            settledCurrency: $nativeCurrency === null ? null : IcsPdfHeaderProfile::STATEMENT_CURRENCY,
        );
    }

    // A row carries a month but no year, and a statement never lists a month past
    // its own — a later month is therefore last year's tail. Transaction and
    // booking months straddle the turn independently, so each resolves alone.
    private static function yearForMonth(int $month, int $statementMonth, int $statementYear): int
    {
        return $month > $statementMonth ? $statementYear - 1 : $statementYear;
    }

    private function extractCounterpartyName(string $description): ?string
    {
        $trimmed = trim($description);
        if ($trimmed === '') {
            return null;
        }

        $stripped = preg_replace(self::TRAILING_COUNTRY_CODE_REGEX, '', $trimmed) ?? $trimmed;
        $compact = trim(preg_replace('/\s{2,}/', ' ', $stripped) ?? $stripped);

        return $compact === '' ? null : $compact;
    }

    private function parseDisplayedFxRate(?string $fxLine): ?string
    {
        if ($fxLine === null) {
            return null;
        }

        if (preg_match('/Wisselkoers\s+[A-Z]{3}\s+([\d.,]+)/', $fxLine, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    private function scrubCardNumbers(string $text): string
    {
        $patterns = [
            '/\*{4}-\*{4}-\*{4}-[^\s]{1,8}/',
            '/\d{12,}/',
        ];

        foreach ($patterns as $pattern) {
            $replaced = preg_replace($pattern, self::SCRUB_LITERAL, $text);
            if (is_string($replaced)) {
                $text = $replaced;
            }
        }

        return $text;
    }

    /**
     * @param  list<CarbonImmutable>  $postedDates
     *
     * @link ../../../../../.docs/conventions/invariants-from-shipped-failures.md#a-period-derived-from-one-column-and-tested-on-another
     */
    private function buildStatementMetadata(
        string $rawText,
        string $ownIban,
        array $postedDates,
        int $entryCount,
        ?string $cardLast4,
    ): StatementSummaryData {
        $fourColumn = $this->parseFourColumnSummary($rawText);
        $twoColumn = $this->parseTwoColumnLimitBlock($rawText);

        $opening = $fourColumn['opening'] ?? null;
        $received = $fourColumn['received'] ?? null;
        $charges = $fourColumn['charges'] ?? null;
        $closing = $fourColumn['closing'] ?? null;
        $creditLimit = $twoColumn['creditLimit'] ?? null;
        $minDue = $twoColumn['minDue'] ?? null;

        // ICS prints the summary block positive with an "Af" marker meaning owed
        // to ICS; the ledger stores what is owed as a negative balance.
        $opening = $opening === null ? null : -$opening;
        $closing = $closing === null ? null : -$closing;
        $charges = $charges === null ? null : -$charges;

        // ICS books a charge on or after the day the card was used, so a period
        // spanning the BOOKED days always opens later than the earliest charge
        // billed on it -- and every reader of this period tests membership on
        // posted_at.
        $periodStart = null;
        $periodEnd = null;
        if ($postedDates !== []) {
            $sorted = $postedDates;
            usort($sorted, static fn (CarbonImmutable $a, CarbonImmutable $b): int => $a <=> $b);
            $periodStart = $sorted[0];
            $periodEnd = $sorted[count($sorted) - 1];
        }

        return new StatementSummaryData(
            importRunId: 0,
            accountId: 0,
            ibanOwner: $ownIban,
            statementNumber: $this->header->statementNumber($rawText),
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            openingBalanceMinor: $opening,
            openingBalanceCurrency: $opening === null ? null : IcsPdfHeaderProfile::STATEMENT_CURRENCY,
            openingBalanceDate: $periodStart,
            closingBalanceMinor: $closing,
            closingBalanceCurrency: $closing === null ? null : IcsPdfHeaderProfile::STATEMENT_CURRENCY,
            closingBalanceDate: $periodEnd,
            entryCount: $entryCount,
            extras: [
                'issuer' => 'Mastercard',
                'cardLast4' => $cardLast4,
                'cardholderName' => 'STRIPPED',
                'totalReceivedMinor' => $received,
                'totalChargesMinor' => $charges,
                'creditLimitMinor' => $creditLimit,
                'minimumDueMinor' => $minDue,
            ],
            paymentDueDate: $this->header->paymentDueDate($rawText),
        );
    }

    /**
     * @return array{opening?: int, received?: int, charges?: int, closing?: int}
     */
    private function parseFourColumnSummary(string $text): array
    {
        $cell = self::AMOUNT_AF_BIJ_FRAGMENT;
        $pattern = '/'.preg_quote(IcsPdfExtractionMap::SUMMARY_OPENING, '/')
            .'.+?'.preg_quote(IcsPdfExtractionMap::SUMMARY_RECEIVED, '/')
            .'.+?'.preg_quote(IcsPdfExtractionMap::SUMMARY_CHARGES, '/')
            .'.+?'.preg_quote(IcsPdfExtractionMap::SUMMARY_CLOSING, '/')
            .'[\s\S]*?'
            .$cell.'\s+'
            .$cell.'\s+'
            .$cell.'\s+'
            .$cell.'/u';

        if (preg_match($pattern, $text, $m) !== 1) {
            return [];
        }

        $opening = $this->safeParseAmount($m[1]);
        $received = $this->safeParseAmount($m[2]);
        $charges = $this->safeParseAmount($m[3]);
        $closing = $this->safeParseAmount($m[4]);

        $out = [];
        if ($opening !== null) {
            $out['opening'] = $opening;
        }
        if ($received !== null) {
            $out['received'] = $received;
        }
        if ($charges !== null) {
            $out['charges'] = $charges;
        }
        if ($closing !== null) {
            $out['closing'] = $closing;
        }

        return $out;
    }

    /**
     * @return array{creditLimit?: int, minDue?: int}
     */
    private function parseTwoColumnLimitBlock(string $text): array
    {
        $pattern = '/'.preg_quote(IcsPdfExtractionMap::SUMMARY_CREDIT_LIMIT, '/')
            .'\s+'.preg_quote(IcsPdfExtractionMap::SUMMARY_MIN_DUE, '/')
            .'[\s\S]*?'
            .'€\s+([\d.,]+)\s+'
            .'€\s+([\d.,]+)/u';

        if (preg_match($pattern, $text, $m) !== 1) {
            return [];
        }

        $creditLimit = $this->safeParseAmount($m[1]);
        $minDue = $this->safeParseAmount($m[2]);

        $out = [];
        if ($creditLimit !== null) {
            $out['creditLimit'] = $creditLimit;
        }
        if ($minDue !== null) {
            $out['minDue'] = $minDue;
        }

        return $out;
    }

    private function safeParseAmount(string $raw): ?int
    {
        try {
            return $this->amounts->parse($raw, IcsPdfHeaderProfile::STATEMENT_CURRENCY);
        } catch (InvalidAmountException) {
            return null;
        }
    }
}
