<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\ValueObjects;

use Brick\Money\Money as BrickMoney;
use IntlException;
use Stringable;
use ValueError;

// Wraps brick/money so domain code never imports brick directly. The
// only way to construct a Money is ofMinor(int, string) — no ofFloat
// or fromString on purpose, since floats and locale-aware string
// parsing are the exact failure modes the integer-only rules prevent.
final class Money implements Stringable
{
    // The app represents every amount as an integer count of 1/100 of a major
    // unit — the 2-decimal minor unit shared by its currencies. Parse and
    // format boundaries scale by this factor; a future non-2-decimal currency
    // (e.g. JPY) would have to make it per-currency rather than a constant.
    public const int MINOR_UNITS_PER_MAJOR = 100;

    private function __construct(private readonly BrickMoney $inner) {}

    // Negative values represent debits (money out); positive values
    // represent credits (money in).
    public static function ofMinor(int $minor, string $currencyCode): self
    {
        return new self(BrickMoney::ofMinor($minor, $currencyCode));
    }

    public function plus(self $other): self
    {
        return new self($this->inner->plus($other->inner));
    }

    public function minus(self $other): self
    {
        return new self($this->inner->minus($other->inner));
    }

    public function toMinor(): int
    {
        return $this->inner->getMinorAmount()->toInt();
    }

    public function currency(): string
    {
        return $this->inner->getCurrency()->getCurrencyCode();
    }

    public function isNegative(): bool
    {
        return $this->inner->isNegative();
    }

    // When $locale is null, EUR renders in Dutch locale (nl_NL) and
    // every other currency in US English (en_US), so foreign amounts
    // read like a card statement while EUR stays in the NL convention.
    // An explicit $locale overrides the auto-selection.
    public function format(?string $locale = null): string
    {
        $resolved = $locale ?? ($this->currency() === 'EUR' ? 'nl_NL' : 'en_US');

        // formatToLocale(), not formatTo(): the latter is deprecated in
        // brick/money 0.11 and gone in 0.14, and it only forwards here anyway
        // after raising a deprecation on every rendered amount.
        try {
            return $this->inner->formatToLocale($resolved);
        } catch (IntlException|ValueError) {
            // The mobile build's ext-intl cannot construct a NumberFormatter
            // for these locales, and reports that two ways — IntlException
            // with intl error-exceptions on, ValueError from the constructor.
            // Every amount funnels here, so an uncaught one 500s every page.
            return $this->formatWithoutIcu();
        }
    }

    // Deliberately plain: currency code, then the amount at a fixed scale with
    // no grouping. A legibility floor for a runtime that cannot do locale
    // formatting, not an imitation of one — guessing separators per currency is
    // how a Dutch reader ends up seeing a point where they expect a comma.
    private function formatWithoutIcu(): string
    {
        return $this->currency().' '.$this->inner->getAmount()->toScale(2);
    }

    public function __toString(): string
    {
        return $this->inner->__toString();
    }
}
