<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Banking;

use Carbon\CarbonImmutable;
use Modules\Core\Public\Support\SafeDate;
use Modules\Ingestion\Internal\Adapters\Banking\Dto\Mt940StatementLine;
use Modules\Ingestion\Internal\Exceptions\InvalidAmountException;
use Modules\Ingestion\Public\Banking\SwiftDate;
use Throwable;

final readonly class Mt940Tag61Parser
{
    private const string REGEX = '/^'
        .'(?P<year>\d{2})(?P<month>\d{2})(?P<day>\d{2})'
        .'(?:(?P<entry_month>\d{2})(?P<entry_day>\d{2}))?'
        .'(?P<status>R?[CD])'
        .'(?P<funds_code>[A-Z])?'
        .'(?P<amount>\d+(?:,\d{0,2})?)'
        .'(?P<id>[A-Z][A-Z0-9 ]{3})?'
        .'(?P<customer_reference>[^\/\n]{0,34})'
        .'(?:\/\/(?P<bank_reference>[^\n]{0,16}))?'
        .'(?:\n(?P<extra_details>.{0,34}))?'
        .'$/';

    public function __construct(
        private BankAmountParser $amounts,
    ) {}

    public function parse(string $content, ?string $currencyCode = null): Mt940StatementLine
    {
        $body = trim($content);

        if (preg_match(self::REGEX, $body, $m) !== 1) {
            throw new InvalidAmountException(sprintf('Unparseable :61: line: %s', $content));
        }

        $valueYear = SwiftDate::yearFor((int) $m['year']);
        $valueMonth = (int) $m['month'];
        $valueDate = $this->parseDate(sprintf('%04d-%02d-%02d', $valueYear, $valueMonth, (int) $m['day']));

        $entryDate = null;
        if ($m['entry_month'] !== '' && $m['entry_day'] !== '') {
            $entryMonth = (int) $m['entry_month'];
            $entryYear = $valueYear + SwiftDate::entryYearOffset($entryMonth, $valueMonth);
            $entryDate = $this->parseDate(sprintf('%04d-%02d-%02d', $entryYear, $entryMonth, (int) $m['entry_day']));
        }

        $amountInteger = $this->parseAmountToMinor($m['amount'], $currencyCode);

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

    private function parseAmountToMinor(string $raw, ?string $currencyCode): int
    {
        try {
            return $this->amounts->parseMt940Minor($raw, $currencyCode);
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
        $parsed = SafeDate::fromFormatOrNull('!Y-m-d', $isoDate);
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
