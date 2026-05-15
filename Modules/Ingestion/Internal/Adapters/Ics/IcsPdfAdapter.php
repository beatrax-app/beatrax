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
 * Streaming parser for Mijn ICS consumer-portal monthly-statement PDFs.
 *
 * The pipeline is:
 *
 *   1. Sniff the upload (`%PDF-` magic-byte) before invoking pdftotext.
 *   2. Extract the spatially-preserved text via the injected
 *      `PdfTextExtractor` (wraps spatie/pdf-to-text).
 *   3. Strip per-page noise (cardholder banner, card watermark,
 *      statement-summary repeating header block, marketing banners,
 *      depositogarantiestelsel disclaimer, body-paragraph directives).
 *   4. Walk the cleaned text line-by-line, identifying transaction rows
 *      by the trailing `Af` / `Bij` column marker that every transaction
 *      carries on the Mijn ICS layout.
 *   5. Pair each transaction line with the following `Wisselkoers …` line
 *      when present so a foreign-currency row yields ONE canonical DTO
 *      carrying both native + settled legs.
 *   6. After parse() exhausts the generator, expose statement-level
 *      metadata (period dates derived from min/max booked_at, opening +
 *      closing balance, period totals, masked-card extras) via
 *      `statementMetadata()` for the import pipeline's StatementSummary
 *      writer to persist.
 *
 * Source-reference policy: the empirical Mijn ICS statement carries no
 * stable per-transaction identifier (authorisation code / slip number /
 * transaction reference), so `sourceRef` is always `null`. The v3
 * FingerprintComposer tuple is therefore the only dedup anchor for ICS
 * PDF rows — the same posture the MT940 adapter takes when an `EREF`
 * keyword is absent.
 *
 * Currency-policy: EUR-native rows leave the DTO's settled pair `null`
 * (NormalizeStage mirrors native into settled and leaves `fxRateUsed`
 * `null`). Foreign-currency rows populate `currency` / `amountMinor`
 * with the native pair AND `settledAmountMinor` / `settledCurrency` with
 * the EUR pair; NormalizeStage derives `fxRateUsed` from those legs.
 *
 * Card-number policy: the per-statement card-watermark line is parsed
 * for the card last-four only (written into
 * `statement_summaries.extras.cardLast4`); the cardholder name is
 * dropped at the adapter boundary regardless of the source content. Any
 * 12+ contiguous digit run or canonical masked-card placeholder
 * encountered inside a per-transaction text block is scrubbed before
 * the block is written into the DTO's `rawPayload` envelope.
 */
final class IcsPdfAdapter implements SourceAdapter
{
    /**
     * Synthetic own-IBAN literal for ICS card accounts. The
     * AccountResolver already scopes lookups to the current user, so a
     * single per-instance literal is sufficient — there is no need for
     * a per-user suffix.
     */
    private const ICS_OWN_IBAN = 'ICS-CARD';

    /** Card-number scrubbing replacement for per-transaction raw payloads. */
    private const SCRUB_LITERAL = '<discarded per security policy>';

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

    /**
     * Returns the statement-level metadata produced by the most recent
     * `parse()` call, or null when parse() has not yet been iterated to
     * completion. The metadata is assembled in the generator's terminator
     * step, so callers must exhaust the iterator (e.g. via
     * `iterator_to_array($generator, false)` or a `foreach` walk to the
     * end) before reading this value — partial iteration leaves
     * statementMetadata() at null.
     */
    public function statementMetadata(): ?StatementSummaryData
    {
        return $this->lastStatementMetadata;
    }

    public function parse(string $localPath, AccountResolver $accounts): Generator
    {
        $this->sniffer->sniff($localPath, IcsPdfHeaderProfile::FORMAT);
        $this->lastStatementMetadata = null;

        // Extraction failures keep their typed identity so callers (the
        // upload wizard, the importer) can render a tailored
        // "pdftotext binary missing — install poppler" message rather
        // than seeing an amount-parser exception.
        $text = $this->extractor->extract($localPath);

        // Read summary tokens FROM THE RAW TEXT before stripping noise so
        // the statement-level metadata can be assembled even though the
        // per-page noise pass would otherwise drop the relevant lines.
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
        // Single resolve() call so the wizard's UnknownAccount branching
        // path still fires for ICS imports (matches every other adapter's
        // shape).
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

    /**
     * Returns the synthetic own-IBAN literal used for every ICS card
     * import. Credit cards have no real IBAN; the AccountResolver
     * scopes lookups by user already, so a single instance-wide
     * literal is unambiguous regardless of the user.
     */
    private function ownIban(): string
    {
        return self::ICS_OWN_IBAN;
    }

    /**
     * Reads the per-statement masked-card line `Uw Card met als laatste
     * vier cijfers <FOUR>` and returns the captured four-character token
     * (digits in production; the placeholder `XXXX` in the committed
     * fixture). Returns null when the line is absent.
     */
    private function parseCardLast4(string $text): ?string
    {
        if (preg_match('/Uw Card met als laatste vier cijfers (\S{4})/', $text, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /**
     * Locates the statement header date (full Dutch month + year shape,
     * e.g. `15 februari 2026`) used to anchor each transaction row's
     * derived year. Returns null when the header line is absent.
     */
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

    /**
     * Removes recurring per-page noise (cardholder banner, card watermark,
     * marketing banners, body-paragraph directives, summary repeating
     * header block) from the extracted text via the regex set defined in
     * `IcsPdfExtractionMap::PAGE_NOISE_PATTERNS`.
     */
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
     * Walks the cleaned text and yields one contiguous text-block per
     * logical transaction. A transaction row is identified by:
     *
     *   - the row begins with `<digit{1,2}> <month-abbrev>.` (the
     *     transactiedatum column);
     *   - and the row ends with the `Af` / `Bij` direction marker.
     *
     * When the next line begins with the literal `Wisselkoers `, that
     * line is folded into the same block (FX-row two-line shape).
     *
     * @return Generator<int, string>
     */
    private function iterateTransactionBlocks(string $text): Generator
    {
        $lines = preg_split('/\r\n|\r|\n/', $text);
        if ($lines === false) {
            return;
        }

        $count = count($lines);
        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            if (! $this->looksLikeTransactionRow($trimmed)) {
                continue;
            }

            $block = $trimmed;
            // Fold the immediately-following Wisselkoers line into the block.
            if ($i + 1 < $count) {
                $next = trim($lines[$i + 1]);
                if (str_starts_with($next, IcsPdfExtractionMap::FX_LINE_ANCHOR)) {
                    $block .= "\n".$next;
                    $i++;
                }
            }

            yield $block;
        }
    }

    private function looksLikeTransactionRow(string $line): bool
    {
        // The row must start with a transactiedatum token and end with
        // the `Af` / `Bij` column marker.
        if (preg_match('/^\d{1,2}\s+(jan|feb|mrt|apr|mei|jun|jul|aug|sep|okt|nov|dec)\.?\s+/i', $line) !== 1) {
            return false;
        }

        return preg_match('/\s(Af|Bij)$/', $line) === 1;
    }

    /**
     * Parses one transaction block (one merchant line, optionally with a
     * second `Wisselkoers …` line folded in) into a
     * `SourceTransactionDto`.
     */
    private function buildDto(string $block, int $rowIndex, string $ownIban, int $statementYear): SourceTransactionDto
    {
        $lines = explode("\n", $block);
        $primary = $lines[0];
        $fxLine = $lines[1] ?? null;

        // Direction marker (last whitespace-separated token).
        if (preg_match('/\s(Af|Bij)$/', $primary, $dirMatch) !== 1) {
            throw new InvalidAmountException(sprintf(
                'ICS transaction row does not end with Af/Bij marker: %s',
                $primary,
            ));
        }
        $direction = $dirMatch[1];
        $withoutDirection = trim(substr($primary, 0, strlen($primary) - strlen($dirMatch[0])));

        // Settled-EUR amount is the trailing whitespace-separated token.
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

        // Native amount + currency (FX rows only). Captured if the
        // remaining trailing tail of the row matches `<amount> <ISO>`.
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

        // Dates: two date tokens at the start of `rest`.
        if (
            preg_match(
                '/^(\d{1,2})\s+(jan|feb|mrt|apr|mei|jun|jul|aug|sep|okt|nov|dec)\.?\s+(\d{1,2})\s+(jan|feb|mrt|apr|mei|jun|jul|aug|sep|okt|nov|dec)\.?\s+(.*)$/i',
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

        // Year inference: the booked date inherits the statement-header
        // year; the transaction date inherits the same year unless the
        // transaction month is December and the booked month is January
        // (a typical month-rollover within a January statement). In
        // practice both columns share the year on every empirical row.
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

        // Build the rawPayload with card-number scrubbing applied to the
        // contiguous text block.
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

    /**
     * Strips the trailing two-letter country code and collapses internal
     * multi-space runs from the Mijn ICS "Omschrijving" column. Returns
     * the cleaned counterparty string, which may still include city or
     * address fragments — the upstream column merges merchant, street,
     * and city into a single free-text field, and the trailing country
     * code is the only stable terminator the adapter can detect without
     * a per-merchant heuristic. The full original description still
     * lives in the DTO's `description` field; this returns only the
     * trimmed counterparty variant used for FingerprintComposer
     * normalisation.
     */
    private function extractCounterpartyName(string $description): ?string
    {
        $trimmed = trim($description);
        if ($trimmed === '') {
            return null;
        }

        // Strip a trailing two-letter country code (` NL`, ` US`, ` GB`,
        // ` LU`, ` IE`, ` MT`, …). Mijn ICS always renders the country in
        // upper-case alpha-2 at the end of the description column.
        $stripped = preg_replace('/\s+[A-Z]{2}$/', '', $trimmed);
        if (! is_string($stripped)) {
            return $trimmed;
        }

        // Compact internal multi-space runs into a single space so the
        // FingerprintComposer's normalisation pass sees a stable shape.
        $compact = preg_replace('/\s{2,}/', ' ', $stripped);
        if (! is_string($compact)) {
            return $stripped;
        }

        return trim($compact) === '' ? null : trim($compact);
    }

    /**
     * Extracts the displayed Dutch-formatted FX rate (`1,14390`) from a
     * `Wisselkoers <CURRENCY> <rate>` line, or null when absent.
     */
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

    /**
     * Replaces any 12+ contiguous digit run OR canonical masked-card
     * placeholder (`****-****-****-XXXX` and variants) in `$text` with
     * the policy literal. Defends the per-transaction raw_payload
     * column from ever carrying card-number characters.
     */
    private function scrubCardNumbers(string $text): string
    {
        $patterns = [
            // Canonical masked-card placeholder (any chars after the last
            // hyphen).
            '/\*{4}-\*{4}-\*{4}-[^\s]{1,8}/',
            // Any 12+ contiguous digit run (real PANs).
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
     * Assembles the statement-level metadata DTO.
     *
     * Period dates derive from min/max booked_at across the parsed rows
     * because the Mijn ICS statement carries no explicit `Periode` field.
     * Opening / closing balances + period totals are parsed from the
     * four-column summary header row on page 1. Credit-limit and
     * minimum-due are parsed from the two-column block lower on page 1.
     * Masked-card metadata lands in `extras` for downstream audit.
     *
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

        // Sign convention: opening, closing and period-charges on a
        // revolving-credit statement are displayed as positive amounts
        // with an `Af` direction marker meaning "owed to ICS"; persist
        // them as signed-negative so the ledger semantics line up with
        // the rest of the project (debits negative, credits positive).
        // Period-received credits stay positive (the `Bij` direction);
        // credit-limit and minimum-due are informational values that
        // remain positive.
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
            openingBalanceCurrency: $opening === null ? null : 'EUR',
            openingBalanceDate: $periodStart,
            closingBalanceMinor: $closing,
            closingBalanceCurrency: $closing === null ? null : 'EUR',
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
     * Parses the four-column summary header row on page 1 in a single
     * regex pass. The row carries opening + received + charges + closing
     * in a single fixed order on the line directly below the four-token
     * header. Returns the four positive integer-minor values keyed by
     * `opening`, `received`, `charges`, `closing`; missing tokens map to
     * absent array keys.
     *
     * @return array{opening?: int, received?: int, charges?: int, closing?: int}
     */
    private function parseFourColumnSummary(string $text): array
    {
        $pattern = '/'.preg_quote(IcsPdfExtractionMap::SUMMARY_OPENING, '/')
            .'.+?'.preg_quote(IcsPdfExtractionMap::SUMMARY_RECEIVED, '/')
            .'.+?'.preg_quote(IcsPdfExtractionMap::SUMMARY_CHARGES, '/')
            .'.+?'.preg_quote(IcsPdfExtractionMap::SUMMARY_CLOSING, '/')
            .'[\s\S]*?'
            .'€\s+([\d.,]+)\s+(?:Af|Bij)\s+'
            .'€\s+([\d.,]+)\s+(?:Af|Bij)\s+'
            .'€\s+([\d.,]+)\s+(?:Af|Bij)\s+'
            .'€\s+([\d.,]+)\s+(?:Af|Bij)/u';

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
     * Parses the two-column block at the foot of page 1 that carries
     * Bestedingslimiet (credit limit, left column) and Minimaal te betalen
     * bedrag (minimum due, right column) on adjacent lines. Returns the
     * two positive integer-minor values keyed by `creditLimit` and
     * `minDue`; missing tokens map to absent array keys.
     *
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

    /**
     * Parses one Dutch-formatted amount string into minor units, returning
     * null when the input is not a valid amount cell rather than throwing.
     */
    private function safeParseAmount(string $raw): ?int
    {
        try {
            return $this->amounts->parse($raw);
        } catch (InvalidAmountException) {
            return null;
        }
    }

    /**
     * Reads the `Volgnummer N` token from the statement header. The
     * value row directly under the `Volgnummer  Bladnummer` two-column
     * header carries the statement date, customer id, sequence number,
     * and sheet index `N van M`. The sequence number is the integer that
     * immediately precedes `\d+\s+van\s+\d+`.
     */
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
