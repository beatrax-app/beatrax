<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Ics;

use Carbon\CarbonImmutable;
use Generator;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Contracts\SourceAdapter;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ingestion\Public\Exceptions\InvalidAmountException;
use Modules\Ingestion\Public\Services\HeaderSniffer;
use Modules\Ledger\Public\Dto\StatementSummaryData;

/**
 * @link ../../../../../.docs/features/ingestion/ics-pdf-text-extraction.md
 */
final class IcsPdfAdapter implements SourceAdapter
{
    // Credit cards carry no IBAN; AccountResolver scopes by user, so one literal is unambiguous.
    private const ICS_OWN_IBAN = 'ICS-CARD';

    private const SCRUB_LITERAL = '<discarded per security policy>';

    // One euro summary cell: an amount followed by its Af/Bij direction marker.
    private const AMOUNT_AF_BIJ_FRAGMENT = '€\s+([\d.,]+)\s+(?:Af|Bij)';

    /** @var array<string, int> */
    private const MONTH_ABBREV = [
        'jan' => 1, 'feb' => 2, 'mrt' => 3, 'apr' => 4,
        'mei' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8,
        'sep' => 9, 'okt' => 10, 'nov' => 11, 'dec' => 12,
    ];

    private ?StatementSummaryData $lastStatementMetadata = null;

    public function __construct(
        private readonly HeaderSniffer $sniffer,
        private readonly PdfTextExtractor $extractor,
        private readonly IcsAmountParser $amounts,
        private readonly IcsDateParser $dates,
    ) {}

    public function format(): string
    {
        return IcsPdfHeaderProfile::FORMAT;
    }

    // Assembled in parse()'s terminator step: an abandoned iterator leaves this null.
    public function statementMetadata(): ?StatementSummaryData
    {
        return $this->lastStatementMetadata;
    }

    public function parse(string $localPath, AccountResolver $accounts): Generator
    {
        $this->sniffer->sniff($localPath, IcsPdfHeaderProfile::FORMAT);
        $this->lastStatementMetadata = null;

        $text = $this->extractor->extract($localPath);

        // stripPageNoise() deletes the very lines the statement metadata is read from.
        $rawText = $text;
        $cleaned = $this->stripPageNoise($text);

        $statementDate = $this->parseStatementDate($rawText);
        $statementYear = $statementDate === null ? (int) date('Y') : $statementDate->year;

        $cardLast4 = $this->parseCardLast4($rawText);

        $index = 0;
        /** @var list<CarbonImmutable> $bookedDates */
        $bookedDates = [];
        $entryCount = 0;
        $ownIban = $this->ownIban();
        // Result discarded: the call is here so the wizard's UnknownAccount
        // branch still fires; ParseStage re-resolves per row downstream.
        $accounts->resolve($ownIban);

        foreach ($this->iterateTransactionBlocks($cleaned) as $block) {
            $dto = $this->buildDto($block, $index, $ownIban, $statementYear);

            yield $dto;
            $bookedDates[] = $dto->bookedAt;
            $index++;
            $entryCount++;
        }

        $this->lastStatementMetadata = $this->buildStatementMetadata(
            rawText: $rawText,
            ownIban: $ownIban,
            bookedDates: $bookedDates,
            entryCount: $entryCount,
            cardLast4: $cardLast4,
        );
    }

    private function ownIban(): string
    {
        return self::ICS_OWN_IBAN;
    }

    private function parseCardLast4(string $text): ?string
    {
        if (preg_match('/Uw Card met als laatste vier cijfers (\S{4})/', $text, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    private function parseStatementDate(string $text): ?CarbonImmutable
    {
        if (
            preg_match(
                '/(\d{1,2})\s+(januari|februari|maart|april|mei|juni|juli|augustus|september|oktober|november|december)\s+(\d{4})/i',
                $text,
                $m,
            ) === 1
        ) {
            try {
                return $this->dates->parse(sprintf('%s %s %s', $m[1], $m[2], $m[3]));
            } catch (InvalidAmountException) {
                return null;
            }
        }

        return null;
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

    private function buildDto(string $block, int $rowIndex, string $ownIban, int $statementYear): SourceTransactionDto
    {
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
        $settledMinor = $this->amounts->parse($settledRaw);
        if ($direction === 'Af') {
            $settledMinor = -$settledMinor;
        }

        $nativeAmountMinor = null;
        $nativeCurrency = null;
        if (preg_match('/(.+?)\s+([\d.,]+)\s+([A-Z]{3})$/', $rest, $fxMatch) === 1) {
            $rest = trim($fxMatch[1]);
            $nativeAmountMinor = $this->amounts->parse($fxMatch[2]);
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

        $txYear = $statementYear;
        $bookYear = $statementYear;
        if ($txMonth === 12 && $bookMonth === 1) {
            $txYear = $statementYear - 1;
        }

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
            currency: $nativeCurrency ?? 'EUR',
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
            settledCurrency: $nativeCurrency === null ? null : 'EUR',
            fxRateUsed: null,
        );
    }

    private function extractCounterpartyName(string $description): ?string
    {
        $trimmed = trim($description);
        if ($trimmed === '') {
            return null;
        }

        // The trailing alpha-2 country code is the only stable terminator; street/city fragments survive.
        $stripped = preg_replace('/\s+[A-Z]{2}$/', '', $trimmed) ?? $trimmed;
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
     * @param  list<CarbonImmutable>  $bookedDates
     */
    private function buildStatementMetadata(
        string $rawText,
        string $ownIban,
        array $bookedDates,
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

        // ICS prints these positive with an "Af" (owed to ICS) marker; ledger semantics need debits negative.
        $opening = $opening === null ? null : -$opening;
        $closing = $closing === null ? null : -$closing;
        $charges = $charges === null ? null : -$charges;

        $periodStart = null;
        $periodEnd = null;
        if ($bookedDates !== []) {
            $sorted = $bookedDates;
            usort($sorted, static fn (CarbonImmutable $a, CarbonImmutable $b): int => $a <=> $b);
            $periodStart = $sorted[0];
            $periodEnd = $sorted[count($sorted) - 1];
        }

        return new StatementSummaryData(
            importRunId: 0,
            accountId: 0,
            ibanOwner: $ownIban,
            statementNumber: $this->parseStatementNumber($rawText),
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
            return $this->amounts->parse($raw);
        } catch (InvalidAmountException) {
            return null;
        }
    }

    private function parseStatementNumber(string $text): ?string
    {
        if (
            preg_match(
                '/Volgnummer\s+Bladnummer\s*\n[^\n]*?(\d+)\s+\d+\s+van\s+\d+/m',
                $text,
                $m,
            ) === 1
        ) {
            return $m[1];
        }

        return null;
    }
}
