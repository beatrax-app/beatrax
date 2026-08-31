<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\ValueObjects;

// The columns one transactions amount actually lives in: the native pair the
// fingerprint is composed over, the settled pair every balance, budget and
// forecast sums, and the rate relating them. They move as a set or the row
// disagrees with its own dedup key.
final readonly class TransactionAmount
{
    public function __construct(
        public int $amountMinor,
        public string $currency,
        public int $settledAmountMinor,
        public string $settledCurrency,
        public ?string $fxRateUsed = null,
    ) {}

    public function isCrossCurrency(): bool
    {
        return $this->currency !== $this->settledCurrency;
    }

    // A receipt names the figure the reader paid, which is the native leg. On a
    // single-currency row the settled leg IS that leg; on a cross-currency one
    // it is the bank's own conversion, which no receipt restates, so it stands
    // and the rate is re-derived from the pair it now sits beside.
    public function withAmountMinor(int $amountMinor): self
    {
        return self::relate(
            $amountMinor,
            $this->currency,
            $this->isCrossCurrency() ? $this->settledAmountMinor : $amountMinor,
            $this->settledCurrency,
        );
    }

    public function withCurrency(string $currency): self
    {
        return self::relate(
            $this->amountMinor,
            $currency,
            $this->settledAmountMinor,
            $this->isCrossCurrency() ? $this->settledCurrency : $currency,
        );
    }

    /**
     * @return array{amount_minor: int, currency: string, settled_amount_minor: int, settled_currency: string, fx_rate_used: string|null}
     */
    public function toColumns(): array
    {
        return [
            'amount_minor' => $this->amountMinor,
            'currency' => $this->currency,
            'settled_amount_minor' => $this->settledAmountMinor,
            'settled_currency' => $this->settledCurrency,
            'fx_rate_used' => $this->fxRateUsed,
        ];
    }

    // The rule in one place, and the only place NormalizeStage and the two
    // enrichment writers reach it through: a rate exists only where the legs are
    // in different currencies, it is the ratio of the pair stored beside it, and
    // that pair carries one sign.
    public static function relate(int $amountMinor, string $currency, int $settledAmountMinor, string $settledCurrency): self
    {
        if ($currency !== $settledCurrency) {
            $settledAmountMinor = self::directedLikeNative($amountMinor, $settledAmountMinor);
        }

        $native = $currency === $settledCurrency ? null : Money::tryOfMinor($amountMinor, $currency);
        $settled = $native === null ? null : Money::tryOfMinor($settledAmountMinor, $settledCurrency);
        $rate = $native === null || $settled === null ? null : Rate::between($settled, $native);

        return new self(
            $amountMinor,
            $currency,
            $settledAmountMinor,
            $settledCurrency,
            $rate === null ? null : (string) $rate,
        );
    }

    // A converted pair is one movement written twice, so the direction belongs
    // to the transaction rather than to either leg: a source that books each leg
    // by the balance IT moved hands over a settled credit for a native debit,
    // which reads as income and inverts the rate. A zero native lends no sign.
    private static function directedLikeNative(int $amountMinor, int $settledAmountMinor): int
    {
        return match (true) {
            $amountMinor < 0 => -abs($settledAmountMinor),
            $amountMinor > 0 => abs($settledAmountMinor),
            default => $settledAmountMinor,
        };
    }
}
