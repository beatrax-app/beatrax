<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Asn;

use Carbon\CarbonImmutable;
use Modules\Ingestion\Internal\Adapters\Asn\Dto\Mt940StatementLine;
use Modules\Ingestion\Public\Exceptions\InvalidAmountException;
use Throwable;

/**
 * Parses a single `:61:` statement-line body into a typed
 * `Mt940StatementLine` value object. The amount is converted to signed
 * integer minor units via the reused `AsnAmountParser` (integer regex,
 * no float coercion).
 *
 * Reads the ASN-extended customer-reference variant: up to 34 chars
 * before the optional `//bankref` separator (the SWIFT-standard variant
 * caps at 16).
 *
 * Status mapping → amount sign:
 *   C  → positive (credit)
 *   D  → negative (debit)
 *   RC → negative (reversal of credit, treated as debit-like)
 *   RD → positive (reversal of debit, treated as credit-like)
 */
final class AsnMt940Tag61Parser
{
    /**
     * Greedy regex over the `:61:` body. Mirrors the ASN dialect:
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
        private readonly AsnAmountParser $amounts,
    ) {}

    public function parse(string $content): Mt940StatementLine
    {
        $body = trim($content);

        if (preg_match(self::REGEX, $body, $m) !== 1) {
            throw new InvalidAmountException(sprintf('Unparseable :61: line: %s', $content));
        }

        $valueDate = $this->parseDate('20'.$m['year'].'-'.$m['month'].'-'.$m['day']);

        $entryDate = ($m['entry_month'] !== '' && $m['entry_day'] !== '')
            ? $this->parseDate('20'.$m['year'].'-'.$m['entry_month'].'-'.$m['entry_day'])
            : null;

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
     * to integer minor units via the reused `AsnAmountParser`. MT940
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

    private function nullIfEmpty(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
