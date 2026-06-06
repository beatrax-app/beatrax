<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Banking;

use Carbon\CarbonImmutable;
use Modules\Ingestion\Internal\Adapters\Banking\Dto\Mt940StatementLine;
use Modules\Ingestion\Public\Exceptions\InvalidAmountException;
use Throwable;

/**
 * Parses a single `:61:` statement-line body into a typed
 * `Mt940StatementLine` value object. The amount is converted to signed
 * integer minor units via the reused `BankAmountParser` (integer regex,
 * no float coercion).
 *
 * Reads the extended customer-reference variant: up to 34 chars
 * before the optional `//bankref` separator (the SWIFT-standard variant
 * caps at 16).
 *
 * Status mapping → amount sign:
 *   C  → positive (credit)
 *   D  → negative (debit)
 *   RC → negative (reversal of credit, treated as debit-like)
 *   RD → positive (reversal of debit, treated as credit-like)
 *
 * Date handling:
 *  - The value date (YYMMDD) is mandatory; the two-digit year is mapped
 *    to a four-digit calendar year via the SWIFT sliding-window rule
 *    (closest year within +/-50 of "now") so the parser stays correct
 *    past the year 2100.
 *  - The optional entry date (MMDD, no year) inherits the value-date
 *    year, except that when the entry month is later than the value
 *    month the entry date is rolled back one calendar year — the
 *    standard SWIFT year-boundary convention for late-December entries
 *    on early-January value dates.
 */
final class Mt940Tag61Parser
{
    /**
     * Greedy regex over the `:61:` body. Mirrors the extended dialect:
     * mandatory value date (YYMMDD), optional entry date (MMDD), status,
     * optional funds-code letter, comma-decimal amount, optional 4-char
     * transaction-type code, optional 34-char customer reference, and an
     * optional `//`-prefixed 16-char bank reference. An optional newline
     * + 34-char extra-details trailer is recognised but not yet wired.
     */
    private const REGEX = '/^'
        .'(?P<year>\d{2})(?P<month>\d{2})(?P<day>\d{2})'
        .'(?:(?P<entry_month>\d{2})(?P<entry_day>\d{2}))?'
        .'(?P<status>R?[CD])'
        .'(?P<funds_code>[A-Z])?'
        .'(?P<amount>\d+(?:,\d{1,2})?)'
        .'(?P<id>[A-Z][A-Z0-9 ]{3})?'
        .'(?P<customer_reference>[^\/\n]{0,34})'
        .'(?:\/\/(?P<bank_reference>[^\n]{0,16}))?'
        .'(?:\n(?P<extra_details>.{0,34}))?'
        .'$/';

    public function __construct(
        private readonly BankAmountParser $amounts,
    ) {}

    public function parse(string $content): Mt940StatementLine
    {
        $body = trim($content);

        if (preg_match(self::REGEX, $body, $m) !== 1) {
            throw new InvalidAmountException(sprintf('Unparseable :61: line: %s', $content));
        }

        $valueYear = $this->resolveSwiftYear((int) $m['year']);
        $valueMonth = (int) $m['month'];
        $valueDate = $this->parseDate(sprintf('%04d-%02d-%02d', $valueYear, $valueMonth, (int) $m['day']));

        $entryDate = null;
        if ($m['entry_month'] !== '' && $m['entry_day'] !== '') {
            $entryMonth = (int) $m['entry_month'];
            // SWIFT year-rollover rule: when the entry month is later than
            // the value month, the entry date belongs to the previous year
            // (e.g. value 2026-01-02, entry 12-31 means entry 2025-12-31).
            $entryYear = $entryMonth > $valueMonth ? $valueYear - 1 : $valueYear;
            $entryDate = $this->parseDate(sprintf('%04d-%02d-%02d', $entryYear, $entryMonth, (int) $m['entry_day']));
        }

        $amountInteger = $this->parseAmountToMinor($m['amount']);

        $status = $m['status'];
        $signed = match ($status) {
            'C', 'RD' => abs($amountInteger),
            'D', 'RC' => -abs($amountInteger),
            default => throw new InvalidAmountException(sprintf('Unknown :61: status code: %s', $status)),
        };

        return new Mt940StatementLine(
            valueDate: $valueDate,
            entryDate: $entryDate,
            status: $status,
            transactionTypeCode: $this->nullIfEmpty($m['id']),
            amountMinor: $signed,
            customerReference: $this->nullIfEmpty($m['customer_reference']),
            bankReference: $this->nullIfEmpty($m['bank_reference'] ?? null),
            extraDetails: $this->nullIfEmpty($m['extra_details'] ?? null),
        );
    }

    /**
     * Converts an MT940 comma-decimal amount (e.g. "1234,56" or "1234")
     * to integer minor units via the reused `BankAmountParser`. MT940
     * sometimes omits the fractional part for whole amounts; the helper
     * normalises to a two-digit period-decimal before delegating so the
     * shared integer parser sees its expected shape.
     */
    private function parseAmountToMinor(string $raw): int
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
            throw new InvalidAmountException(
                sprintf('Unparseable :61: amount %s: %s', $raw, $e->getMessage()),
                0,
                $e,
            );
        }
    }

    private function parseDate(string $isoDate): CarbonImmutable
    {
        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $isoDate);
        if (! $parsed instanceof CarbonImmutable) {
            throw new InvalidAmountException(sprintf('Bad MT940 date: %s', $isoDate));
        }

        return $parsed;
    }

    /**
     * Resolves an MT940 two-digit year (YY) to its four-digit calendar year
     * using the SWIFT sliding-window convention: pick the closest year
     * within +/-50 of "now" so the parser keeps working past the year 2100
     * without a code change.
     */
    private function resolveSwiftYear(int $yy): int
    {
        $today = CarbonImmutable::now();
        $century = ((int) ($today->year / 100)) * 100;
        $candidate = $century + $yy;

        if ($candidate - $today->year > 50) {
            return $candidate - 100;
        }
        if ($today->year - $candidate > 50) {
            return $candidate + 100;
        }

        return $candidate;
    }

    private function nullIfEmpty(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
