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

        $state = new Mt940StatementAccumulator;

        foreach ($this->lexer->tokenize($localPath) as [$tag, $content]) {
            // The :61:/:86: entry pair streams DTOs; every other tag only
            // accumulates header/balance state into $state.
            if ($tag === '61') {
                yield from $this->handleEntryLine($state, $content);

                continue;
            }
            if ($tag === '86') {
                yield from $this->handleNarrative($state, $content);

                continue;
            }

            $this->applyHeaderTag($state, $tag, $content, $accounts);
        }

        if ($state->pendingTag61 !== null) {
            yield $this->buildDto($state->pendingTag61, null, (string) $state->ownIban, (string) $state->currency, $state->rowIndex);
        }

        $this->lastStatementMetadata = $state->toMetadata();
    }

    private function applyHeaderTag(Mt940StatementAccumulator $state, string $tag, string $content, AccountResolver $accounts): void
    {
        // Unknown tags are ignored on purpose — MT940 files carry vendor
        // extensions the ledger has no use for, and dropping them keeps the
        // adapter tolerant of ASN export revisions.
        match ($tag) {
            '20' => $this->applyStatementId($state, $content),
            '25' => $this->applyOwnIban($state, $content, $accounts),
            '28C' => $this->applyStatementNumber($state, $content),
            '60F', '60M' => $this->applyOpeningBalance($state, $content),
            '62F', '62M' => $this->applyClosingBalance($state, $content),
            default => null,
        };
    }

    private function applyStatementId(Mt940StatementAccumulator $state, string $content): void
    {
        if ($state->statementId !== null) {
            $state->multiStatement = true;
        }
        if (! $state->firstStatementFrozen) {
            $state->statementId = trim($content);
        }
    }

    private function applyOwnIban(Mt940StatementAccumulator $state, string $content, AccountResolver $accounts): void
    {
        if ($state->firstStatementFrozen) {
            return;
        }

        $state->ownIban = trim($content);
        $accounts->resolve($state->ownIban);
    }

    private function applyStatementNumber(Mt940StatementAccumulator $state, string $content): void
    {
        if (! $state->firstStatementFrozen) {
            $state->statementNumber = trim($content);
        }
    }

    private function applyOpeningBalance(Mt940StatementAccumulator $state, string $content): void
    {
        if ($state->firstStatementFrozen) {
            return;
        }

        $state->openingBalance = $this->parseBalance($content);
        if ($state->openingBalance !== null) {
            $state->currency = $state->openingBalance->currency;
        }
    }

    private function applyClosingBalance(Mt940StatementAccumulator $state, string $content): void
    {
        if (! $state->firstStatementFrozen) {
            $state->closingBalance = $this->parseBalance($content);
            $state->firstStatementFrozen = true;
        }
    }

    /**
     * @return Generator<int, SourceTransactionDto>
     */
    private function handleEntryLine(Mt940StatementAccumulator $state, string $content): Generator
    {
        if ($state->ownIban === null) {
            throw new InvalidAmountException(
                'MT940 :61: encountered before :25:; file is malformed.',
            );
        }
        if ($state->currency === null) {
            throw new InvalidAmountException(
                'MT940 :61: encountered before any balance tag set a currency.',
            );
        }

        if ($state->pendingTag61 !== null) {
            yield $this->buildDto($state->pendingTag61, null, $state->ownIban, $state->currency, $state->rowIndex);
            $state->rowIndex++;
        }

        $state->pendingTag61 = $this->tag61->parse($content);
        if (! $state->firstStatementFrozen) {
            $state->entryCount++;
        }
    }

    /**
     * @return Generator<int, SourceTransactionDto>
     */
    private function handleNarrative(Mt940StatementAccumulator $state, string $content): Generator
    {
        if ($state->pendingTag61 === null) {
            return;
        }

        $narrative = $this->tag86->parse($content);
        // $ownIban + $currency are guaranteed non-null by the :61: branch,
        // which is the only path that can populate $pendingTag61.
        yield $this->buildDto($state->pendingTag61, $narrative, (string) $state->ownIban, (string) $state->currency, $state->rowIndex);
        $state->rowIndex++;
        $state->pendingTag61 = null;
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

        $date = CarbonImmutable::createFromFormat('!ymd', $m[2]);
        $magnitude = $this->tryParseBalanceAmount($m[4]);
        if (! $date instanceof CarbonImmutable || $magnitude === null) {
            return null;
        }

        return new Mt940BalanceTuple(
            minor: ($m[1] === 'D' ? -1 : 1) * $magnitude,
            currency: $m[3],
            date: $date,
        );
    }

    private function tryParseBalanceAmount(string $raw): ?int
    {
        try {
            return $this->parseBalanceAmount($raw);
        } catch (InvalidAmountException) {
            return null;
        }
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
