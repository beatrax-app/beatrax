<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\ValueObjects;

use Brick\Money\Money as BrickMoney;
use IntlException;
use Modules\Core\Public\Enums\Locale;
use Modules\Ledger\Public\Enums\Currency;
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

    // Only reached when ICU is unavailable. A currency with no entry here
    // renders as its code, which is what ICU itself does for a currency the
    // locale has no symbol for.
    private const array SYMBOLS = [
        'EUR' => '€',
        'USD' => '$',
        'GBP' => '£',
        'JPY' => '¥',
    ];

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
            return $this->formatWithoutIcu($resolved);
        }
    }

    // Renders the two conventions this class anchors on — Dutch for EUR, US
    // English otherwise — from marks the repo carries itself, so a runtime
    // with no locale data still reads € 1.234,50 rather than a bare code and
    // an ungrouped number. Any other locale resolves to one of those two.
    private function formatWithoutIcu(string $locale): string
    {
        $language = Locale::tryFrom((string) strtok(strtolower($locale), '_-'));
        $amount = (string) $this->inner->getAmount();

        if ($language !== Locale::Nl && $language !== Locale::En) {
            $language = $this->currency() === Currency::Eur->value ? Locale::Nl : Locale::En;
        }

        $negative = str_starts_with($amount, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($amount, '-')), 2, '');
        $grouped = strrev(implode($language->groupMark(), str_split(strrev($whole), 3)));
        $sign = $negative ? '-' : '';

        if ($fraction !== '') {
            $grouped .= $language->decimalMark().$fraction;
        }

        $symbol = self::SYMBOLS[$this->currency()] ?? $this->currency()."\u{00A0}";

        // Dutch writes the symbol first and the sign against the digits
        // (€ -1.234,50); US English signs the whole amount (-$1,234.50).
        return $language === Locale::Nl
            ? $symbol."\u{00A0}".$sign.$grouped
            : $sign.$symbol.$grouped;
    }

    public function __toString(): string
    {
        return $this->inner->__toString();
    }
}
