<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Adapters\EnableBanking;

use Spatie\LaravelData\Data;

/**
 * Typed boundary DTO for one row of Enable Banking's `GET
 * /accounts/{uid}/transactions` response (RESEARCH.md Architecture
 * Patterns §2 field table). Every documented EB transaction field is
 * typed here BEFORE `EnableBankingSourceAdapter` maps it onto the
 * canonical `SourceTransactionDto` — nothing downstream ever touches the
 * raw decoded JSON array directly (T-19-08-02).
 *
 * Nested EB objects (`transaction_amount`, `creditor`/`debtor`,
 * `creditor_account`/`debtor_account`) are flattened onto this single
 * class rather than split into per-object Data subclasses: the codebase
 * convention is strictly one class per file (verified: zero multi-class
 * files exist under `Modules/`), and `Task 1`'s file scope is this one
 * file, so flattening avoids either violating that convention or
 * spilling extra files outside the plan's declared scope.
 *
 * `status === 'BOOK'` is the only value the pipeline is allowed to
 * consume (`isBooked()`); PSD2 also reports `'PDNG'` (pending) rows,
 * which `EnableBankingSourceAdapter` must filter out before yielding.
 */
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

    /**
     * `status` is documented as `'BOOK'` for booked entries; anything
     * else (e.g. `'PDNG'`) must never reach the canonical pipeline
     * (RESEARCH.md Pattern 2 booked-only filter, T-19-08-03).
     */
    public function isBooked(): bool
    {
        return $this->status === 'BOOK';
    }

    /**
     * Decodes one raw EB transaction row (as produced by
     * `json_decode($response, true)`) into a typed instance. Every
     * nested access is narrowed via `is_array`/`is_string` checks so no
     * `mixed` value leaks past this boundary (PHPStan level 10 strict).
     *
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
     * Decodes a full `transactions` array (as found under the
     * `transactions` key of the aggregator's response body) into a list
     * of typed instances.
     *
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
