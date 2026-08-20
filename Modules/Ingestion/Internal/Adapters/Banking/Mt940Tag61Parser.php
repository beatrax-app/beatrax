<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Banking;

use Carbon\CarbonImmutable;
use Modules\Ingestion\Internal\Adapters\Banking\Dto\Mt940StatementLine;
use Modules\Ingestion\Public\Exceptions\InvalidAmountException;
use Throwable;

final class Mt940Tag61Parser
{
    // SWIFT sliding pivot: a `yy` further than this from now rolls a century.
    private const int SWIFT_YEAR_WINDOW = 50;

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
            // SWIFT rollover: an entry month later than the value month means
            // the previous year — value 2026-01-02, entry 12-31 is 2025-12-31.
            $entryYear = $entryMonth > $valueMonth ? $valueYear - 1 : $valueYear;
            $entryDate = $this->parseDate(sprintf('%04d-%02d-%02d', $entryYear, $entryMonth, (int) $m['entry_day']));
        }

        $amountInteger = $this->parseAmountToMinor($m['amount']);

        $status = $m['status'];
        // The regex admits only C, D, RC and RD. RD is a reversed debit and so
        // signs positive; RC is a reversed credit and signs negative with D.
        $signed = match ($status) {
            'C', 'RD' => abs($amountInteger),
            default => -abs($amountInteger),
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

    // MT940 omits the fractional part on a whole amount.
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

    private function resolveSwiftYear(int $yy): int
    {
        $today = CarbonImmutable::now();
        $century = ((int) ($today->year / 100)) * 100;
        $candidate = $century + $yy;

        if ($candidate - $today->year > self::SWIFT_YEAR_WINDOW) {
            return $candidate - 100;
        }
        if ($today->year - $candidate > self::SWIFT_YEAR_WINDOW) {
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
