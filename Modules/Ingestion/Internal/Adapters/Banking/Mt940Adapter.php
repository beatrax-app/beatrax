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
 * @link ../../../../../.docs/features/ingestion/architecture.md
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

    // Parses e.g. "C260401EUR1000,00" into a signed integer minor amount, a
    // 3-letter currency, and the balance date, routing the comma-decimal
    // amount through BankAmountParser so the integer-only path holds
    // end-to-end.
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

    // Normalises a comma-decimal cell ("1000,00" / "1000") to a
    // two-fractional-digit period-decimal before delegating to the
    // integer-only amount parser.
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
