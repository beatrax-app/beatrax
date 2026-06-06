<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Banking;

use Carbon\CarbonImmutable;
use Generator;
use Modules\Ingestion\Internal\Adapters\Banking\Dto\Mt940BalanceTuple;
use Modules\Ingestion\Internal\Adapters\Banking\Dto\Mt940Narrative;
use Modules\Ingestion\Internal\Adapters\Banking\Dto\Mt940StatementLine;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Contracts\SourceAdapter;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ingestion\Public\Exceptions\InvalidAmountException;
use Modules\Ingestion\Public\Services\HeaderSniffer;
use Modules\Ledger\Public\Dto\StatementSummaryData;
use Throwable;

/**
 * Hand-rolled MT940 (legacy SWIFT statement) parser for bank exports.
 * Pairs each `:61:` statement-line tag with the optional immediately-
 * following `:86:` narrative tag; produces one SourceTransactionDto per
 * pair. After `parse()` completes, `statementMetadata()` returns the
 * statement-level metadata (opening + closing balance, period dates,
 * entry count) captured from the file's `:20:`, `:25:`, `:28C:`, `:60F:`,
 * and `:62F:` tags so the import pipeline can populate the
 * `statement_summaries` row.
 *
 * Source-reference policy:
 *  - When the `:86:` GVC narrative carries an `EREF` keyword that is
 *    non-empty and not the literal `NOTPROVIDED`, that value becomes
 *    `sourceRef`.
 *  - Otherwise the `:61:` customer-reference (the 34-char extended
 *    variant) is used.
 *  - Otherwise `sourceRef` stays null. MT940's reference channel is
 *    intentionally weaker than CAMT.053's `EndToEndId`; a CAMT enrichment
 *    pass may overwrite this value later in the pipeline.
 *
 * Booking-date normalisation:
 *  - MT940's `:61:` carries a value date (YYMMDD) and an optional entry
 *    date (MMDD), both at day precision. The adapter zeroes `bookedAt`
 *    to `00:00:00` (matching the CSV and CAMT.053 adapters) so a single
 *    logical transaction produces the same FingerprintComposer v3 hash
 *    across all three formats.
 *
 * Multi-statement files:
 *  - When a file carries multiple `:20:` blocks, the FIRST statement's
 *    metadata is captured for `statement_summaries` and the persisted
 *    `entry_count` reflects only that first statement. Subsequent
 *    statements' entries still yield SourceTransactionDto rows; the
 *    `extras` envelope on the statement metadata carries
 *    `multiStatement: true` so downstream views can surface the fact.
 *
 * Source-format integrity:
 *  - `:25:` (own IBAN) and `:60F:`/`:60M:` (currency-bearing opening
 *    balance) must precede the first `:61:`. A `:61:` tag observed
 *    before either is rejected as a parse error so empty IBAN and
 *    silent-default-EUR currency can never reach the import pipeline.
 */
final class Mt940Adapter implements SourceAdapter
{
    private ?StatementSummaryData $lastStatementMetadata = null;

    public function __construct(
        private readonly HeaderSniffer $sniffer,
        private readonly Mt940Lexer $lexer,
        private readonly Mt940Tag61Parser $tag61,
        private readonly Mt940Tag86Parser $tag86,
        private readonly Mt940CounterpartyCleaner $counterpartyCleaner,
        private readonly BankAmountParser $amounts,
    ) {}

    public function format(): string
    {
        return Mt940HeaderProfile::FORMAT;
    }

    public function statementMetadata(): ?StatementSummaryData
    {
        return $this->lastStatementMetadata;
    }

    public function parse(string $localPath, AccountResolver $accounts): Generator
    {
        $this->sniffer->sniff($localPath, Mt940HeaderProfile::FORMAT);
        $this->lastStatementMetadata = null;

        $statementId = null;
        $ownIban = null;
        $statementNumber = null;
        $openingBalance = null;
        $closingBalance = null;
        $entryCount = 0;
        $multiStatement = false;
        $firstStatementFrozen = false;
        $currency = null;

        $pendingTag61 = null;
        $rowIndex = 0;

        foreach ($this->lexer->tokenize($localPath) as [$tag, $content]) {
            switch ($tag) {
                case '20':
                    if ($statementId !== null) {
                        $multiStatement = true;
                    }
                    if (! $firstStatementFrozen) {
                        $statementId = trim($content);
                    }
                    break;

                case '25':
                    if (! $firstStatementFrozen) {
                        $ownIban = trim($content);
                        $accounts->resolve($ownIban);
                    }
                    break;

                case '28C':
                    if (! $firstStatementFrozen) {
                        $statementNumber = trim($content);
                    }
                    break;

                case '60F':
                case '60M':
                    if (! $firstStatementFrozen) {
                        $openingBalance = $this->parseBalance($content);
                        if ($openingBalance !== null) {
                            $currency = $openingBalance->currency;
                        }
                    }
                    break;

                case '62F':
                case '62M':
                    if (! $firstStatementFrozen) {
                        $closingBalance = $this->parseBalance($content);
                        $firstStatementFrozen = true;
                    }
                    break;

                case '61':
                    if ($ownIban === null) {
                        throw new InvalidAmountException(
                            'MT940 :61: encountered before :25:; file is malformed.',
                        );
                    }
                    if ($currency === null) {
                        throw new InvalidAmountException(
                            'MT940 :61: encountered before any balance tag set a currency.',
                        );
                    }
                    if ($pendingTag61 !== null) {
                        yield $this->buildDto($pendingTag61, null, $ownIban, $currency, $rowIndex);
                        $rowIndex++;
                    }
                    $pendingTag61 = $this->tag61->parse($content);
                    if (! $firstStatementFrozen) {
                        $entryCount++;
                    }
                    break;

                case '86':
                    if ($pendingTag61 !== null) {
                        $narrative = $this->tag86->parse($content);
                        // $ownIban + $currency are guaranteed non-null by the
                        // :61: branch above, which is the only path that can
                        // populate $pendingTag61.
                        yield $this->buildDto($pendingTag61, $narrative, (string) $ownIban, (string) $currency, $rowIndex);
                        $rowIndex++;
                        $pendingTag61 = null;
                    }
                    break;
            }
        }

        if ($pendingTag61 !== null) {
            yield $this->buildDto($pendingTag61, null, (string) $ownIban, (string) $currency, $rowIndex);
        }

        if ($statementId !== null && $ownIban !== null) {
            $extras = ['statementId' => $statementId];
            if ($multiStatement) {
                $extras['multiStatement'] = true;
            }

            $this->lastStatementMetadata = new StatementSummaryData(
                importRunId: 0,
                accountId: 0,
                ibanOwner: $ownIban,
                statementNumber: $statementNumber,
                periodStart: $openingBalance?->date,
                periodEnd: $closingBalance?->date,
                openingBalanceMinor: $openingBalance?->minor,
                openingBalanceCurrency: $openingBalance?->currency,
                openingBalanceDate: $openingBalance?->date,
                closingBalanceMinor: $closingBalance?->minor,
                closingBalanceCurrency: $closingBalance?->currency,
                closingBalanceDate: $closingBalance?->date,
                entryCount: $entryCount,
                extras: $extras,
            );
        }
    }

    /**
     * Builds one SourceTransactionDto from a `:61:`/`:86:` pair (or a
     * lone `:61:` when no narrative follows).
     */
    private function buildDto(Mt940StatementLine $line, ?Mt940Narrative $narrative, string $ownIban, string $currency, int $rowIndex): SourceTransactionDto
    {
        $rawName = $narrative?->counterpartyName;
        $counterpartyName = $rawName === null ? null : $this->counterpartyCleaner->clean($rawName);
        if ($counterpartyName !== null && $counterpartyName === '') {
            $counterpartyName = null;
        }

        $counterpartyIban = $narrative?->counterpartyIban;

        $eref = $narrative?->gvcKeywords['EREF'] ?? null;
        $sourceRef = ($eref !== null && $eref !== '' && $eref !== 'NOTPROVIDED')
            ? $eref
            : $line->customerReference;

        $bookedAt = $line->valueDate->startOfDay();

        return new SourceTransactionDto(
            bookedAt: $bookedAt,
            postedAt: $line->valueDate,
            valueDate: $line->valueDate,
            ownIban: $ownIban,
            counterpartyIban: $counterpartyIban,
            counterpartyName: $counterpartyName,
            currency: $currency,
            amountMinor: $line->amountMinor,
            sourceRef: $sourceRef,
            description: $narrative?->description,
            rawPayload: [
                'mt940' => [
                    'gvcCode' => $narrative?->gvcCode,
                    'gvcKeywords' => $narrative === null ? [] : $narrative->gvcKeywords,
                    'customerReference' => $line->customerReference,
                    'bankReference' => $line->bankReference,
                    'transactionTypeCode' => $line->transactionTypeCode,
                    'status' => $line->status,
                    'entryDate' => $line->entryDate?->toDateString(),
                    'rawNarrative' => $narrative?->rawText,
                ],
            ],
            sourceRowIndex: $rowIndex,
        );
    }

    /**
     * Parses a `:60F:` / `:62F:` balance tag content (e.g.
     * `C260401EUR1000,00`) into a signed integer minor amount, a 3-letter
     * currency code, and the balance date. Routes the comma-decimal
     * amount through `BankAmountParser` so the integer-only money path is
     * preserved end-to-end.
     */
    private function parseBalance(string $content): ?Mt940BalanceTuple
    {
        if (preg_match('/^([CD])(\d{6})([A-Z]{3})([\d,]+)$/', trim($content), $m) !== 1) {
            return null;
        }

        $sign = $m[1] === 'D' ? -1 : 1;

        $date = CarbonImmutable::createFromFormat('!ymd', $m[2]);
        if (! $date instanceof CarbonImmutable) {
            return null;
        }

        try {
            $magnitude = $this->parseBalanceAmount($m[4]);
        } catch (InvalidAmountException) {
            return null;
        }

        return new Mt940BalanceTuple(
            minor: $sign * $magnitude,
            currency: $m[3],
            date: $date,
        );
    }

    /**
     * Routes a balance amount string ("1000,00" / "1000") through the
     * integer-only amount parser. The comma-decimal cell is normalised
     * to a two-fractional-digit period-decimal before delegation.
     */
    private function parseBalanceAmount(string $raw): int
    {
        $normalised = str_replace(',', '.', $raw);
        if (! str_contains($normalised, '.')) {
            $normalised .= '.00';
        } elseif (preg_match('/\.\d$/', $normalised) === 1) {
            $normalised .= '0';
        }

        try {
            return $this->amounts->parseMinor($normalised);
        } catch (Throwable $e) {
            throw new InvalidAmountException(sprintf('Bad MT940 balance amount %s: %s', $raw, $e->getMessage()), 0, $e);
        }
    }
}
