<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\ValueObjects;

use Brick\Math\RoundingMode;
use Brick\Money\Exception\UnknownCurrencyException;
use Brick\Money\Money as BrickMoney;
use Illuminate\Container\Container;
use Illuminate\Contracts\Translation\Translator;
use IntlException;
use Modules\Core\Public\Enums\Locale;
use Modules\Ledger\Public\Exceptions\CurrencyMismatchException;
use Stringable;
use ValueError;

/**
 * @link ../../../../.docs/features/ledger/money-formatting.md
 */
final class Money implements Stringable
{
    // The 2-decimal scale factor every parse and format boundary in the repo
    // multiplies by. Currency::Jpy is declared today and JPY has no minor
    // unit, so a JPY amount built through this constant is 100x too large.
    public const int MINOR_UNITS_PER_MAJOR = 100;

    private const array SYMBOLS = [
        'EUR' => '€',
        'USD' => '$',
        'GBP' => '£',
        'JPY' => '¥',
    ];

    private function __construct(private readonly BrickMoney $inner) {}

    // The only constructor — no ofFloat, no fromString, on purpose. Negative
    // is a debit (money out), positive a credit (money in).
    public static function ofMinor(int $minor, string $currencyCode): self
    {
        return new self(BrickMoney::ofMinor($minor, $currencyCode));
    }

    // Null for a currency code that is not one. An amount crossing in from a
    // bank API carries whatever the sender put in that field, and a whole
    // fetch must not die on one row.
    public static function tryOfMinor(int $minor, string $currencyCode): ?self
    {
        try {
            return self::ofMinor($minor, $currencyCode);
        } catch (UnknownCurrencyException) {
            return null;
        }
    }

    /** @throws CurrencyMismatchException */
    public function plus(self $other): self
    {
        return new self($this->inner->plus($this->sameCurrencyAs($other)->inner));
    }

    /** @throws CurrencyMismatchException */
    public function minus(self $other): self
    {
        return new self($this->inner->minus($this->sameCurrencyAs($other)->inner));
    }

    // False across currencies rather than a throw: a caller asking whether
    // two amounts match wants an answer, and EUR 10 does not match USD 10.
    public function equals(self $other): bool
    {
        return $this->currency() === $other->currency()
            && $this->inner->getAmount()->isEqualTo($other->inner->getAmount());
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

    // No locale argument: the reader's active one decides, and a call site
    // passing the one it happens to have is how "US$ -1.245,67" reached the
    // transactions list. The currency is what is rendered, never how.
    public function format(): string
    {
        try {
            return $this->inner->formatToLocale($this->language()->value);
        } catch (IntlException|ValueError) {
            // The mobile build's ICU carries English-only locale data, so
            // every other language throws. Not fixable by bundling more.
            return $this->formatWithoutIcu();
        }
    }

    // Public so the rendering mobile actually gets can be asserted against the
    // ICU one. The two reading alike is the guarantee, and it is untestable
    // while the fallback can only be reached by making ICU throw.
    public function formatWithoutIcu(): string
    {
        $amount = (string) $this->inner->getAmount();
        [$whole, $fraction] = array_pad(explode('.', ltrim($amount, '-')), 2, '');
        $digits = $this->group($whole);

        if ($fraction !== '') {
            $digits .= $this->language()->decimalMark().$fraction;
        }

        return $this->assemble($digits, str_starts_with($amount, '-'));
    }

    // Whole units, for a surface with no room for cents: a calendar cell is
    // four characters wide, and a magnitude there beats an exact figure that
    // wraps. Rounds half-up, and never reaches for the minor unit itself —
    // JPY has none, so dividing by a hundred would render a hundredth of it.
    public function formatWholeUnits(): string
    {
        $rounded = (string) $this->inner->getAmount()->toScale(0, RoundingMode::HalfUp);

        return $this->assemble($this->group(ltrim($rounded, '-')), str_starts_with($rounded, '-'));
    }

    // Chunked from the right by reversing the digits, then turned back before
    // the mark goes in: strrev() over the assembled string would split the two
    // bytes of a non-breaking space, which is the group mark in eleven locales.
    private function group(string $whole): string
    {
        $chunks = array_map(strrev(...), str_split(strrev($whole), 3));

        return implode($this->language()->groupMark(), array_reverse($chunks));
    }

    private function assemble(string $digits, bool $negative): string
    {
        $locale = $this->language();
        $known = self::SYMBOLS[$this->currency()] ?? null;
        $symbol = $known ?? $this->currency();
        $sign = $negative ? '-' : '';

        // ICU spaces an alphabetic symbol off the digits whatever the locale's
        // own pattern says, which is how a currency it has no sign for reads
        // "CHF 3,850.00" in English.
        $gap = $known === null ? "\u{00A0}" : $locale->symbolGap();

        if (! $locale->symbolBeforeAmount()) {
            return $sign.$digits.$gap.$symbol;
        }

        return $locale->signPrecedesSymbol()
            ? $sign.$symbol.$gap.$digits
            : $symbol.$gap.$sign.$digits;
    }

    // The reader's locale, which is what decides separators and symbol
    // position; the currency decides only which symbol is placed. Anything
    // unrecognised reads as English rather than throwing mid-render.
    private function language(): Locale
    {
        return Locale::tryFrom(Container::getInstance()->make(Translator::class)->getLocale()) ?? Locale::En;
    }

    public function __toString(): string
    {
        return $this->inner->__toString();
    }

    private function sameCurrencyAs(self $other): self
    {
        if ($this->currency() !== $other->currency()) {
            throw CurrencyMismatchException::between($this->currency(), $other->currency());
        }

        return $other;
    }
}
