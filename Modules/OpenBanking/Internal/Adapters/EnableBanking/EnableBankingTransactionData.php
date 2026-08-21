<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Adapters\EnableBanking;

use Spatie\LaravelData\Data;

final class EnableBankingTransactionData extends Data
{
    /**
     * @param  list<string>  $remittanceInformation
     */
    public function __construct(
        public readonly string $bookingDate,
        public readonly string $valueDate,
        public readonly string $amount,
        public readonly string $currency,
        public readonly string $creditDebitIndicator,
        public readonly ?string $creditorName,
        public readonly ?string $creditorIban,
        public readonly ?string $debtorName,
        public readonly ?string $debtorIban,
        public readonly array $remittanceInformation,
        public readonly string $status,
        public readonly string $transactionId,
        public readonly string $entryReference,
        public readonly ?string $bankTransactionDomain,
        public readonly ?string $bankTransactionFamily,
        public readonly ?string $bankTransactionSubFamily,
    ) {}

    // 'BOOK' is the only status booked entries carry; anything else (e.g.
    // 'PDNG' pending) must never reach the canonical pipeline.
    public function isBooked(): bool
    {
        return $this->status === 'BOOK';
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        $amountBlock = $row['transaction_amount'] ?? null;
        $amount = is_array($amountBlock) ? $amountBlock : [];

        $creditorBlock = self::arrayOrEmpty($row['creditor'] ?? null);
        $debtorBlock = self::arrayOrEmpty($row['debtor'] ?? null);
        $creditorAccountBlock = self::arrayOrEmpty($row['creditor_account'] ?? null);
        $debtorAccountBlock = self::arrayOrEmpty($row['debtor_account'] ?? null);
        $btcBlock = self::arrayOrEmpty($row['bank_transaction_code'] ?? null);

        return new self(
            bookingDate: self::stringOrEmpty($row['booking_date'] ?? null),
            valueDate: self::stringOrEmpty($row['value_date'] ?? null),
            amount: self::stringOrEmpty($amount['amount'] ?? null),
            currency: self::stringOrEmpty($amount['currency'] ?? null),
            creditDebitIndicator: self::stringOrEmpty($row['credit_debit_indicator'] ?? null),
            creditorName: self::nullableString($creditorBlock['name'] ?? null),
            creditorIban: self::nullableString($creditorAccountBlock['iban'] ?? null),
            debtorName: self::nullableString($debtorBlock['name'] ?? null),
            debtorIban: self::nullableString($debtorAccountBlock['iban'] ?? null),
            remittanceInformation: self::listOfStrings($row['remittance_information'] ?? null),
            status: self::stringOrEmpty($row['status'] ?? null),
            transactionId: self::stringOrEmpty($row['transaction_id'] ?? null),
            entryReference: self::stringOrEmpty($row['entry_reference'] ?? null),
            bankTransactionDomain: self::nullableString($btcBlock['domain'] ?? null),
            bankTransactionFamily: self::nullableString($btcBlock['family'] ?? null),
            bankTransactionSubFamily: self::nullableString($btcBlock['sub_family'] ?? null),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<self>
     */
    public static function collectionFromArray(array $rows): array
    {
        return array_map(self::fromArray(...), $rows);
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function arrayOrEmpty(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private static function stringOrEmpty(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private static function nullableString(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private static function listOfStrings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $lines = [];
        foreach ($value as $line) {
            if (is_string($line)) {
                $lines[] = $line;
            }
        }

        return $lines;
    }
}
