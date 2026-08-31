<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\ValueObjects;

use Brick\Math\RoundingMode;
use Brick\Money\Exception\UnknownCurrencyException;
use Brick\Money\Money as BrickMoney;
use IntlException;
use Modules\Ledger\Internal\Support\MoneyText;
use Modules\Ledger\Public\Exceptions\CurrencyMismatchException;
use Stringable;
use ValueError;

/**
 * @link ../../../../.docs/features/ledger/money-formatting.md
 */
final readonly class Money implements Stringable
{
    // The 2-decimal scale factor every parse and format boundary in the repo
    // multiplies by. Currency::Jpy is declared today and JPY has no minor
    // unit, so a JPY amount built through this constant is 100x too large.
    public const int MINOR_UNITS_PER_MAJOR = 100;

    // Public because an import adapter has to strip these glyphs off a figure
    // before parsing it, and a second list would drift from what format() writes.
    public const array SYMBOLS = [
        'EUR' => '€',
        'USD' => '$',
        'GBP' => '£',
        'JPY' => '¥',
    ];

    // The glyph alone, for a label that names the currency a field is typed in
    // rather than printing an amount. A code with no glyph answers itself, the
    // same fallback the rendering takes when it places a symbol.
    public static function symbolFor(string $currencyCode): string
    {
        return self::SYMBOLS[$currencyCode] ?? $currencyCode;
    }

    // The way back, for a parser reading a figure a message marked with a
    // glyph rather than a code. A second glyph list in the reader would drift
    // from the one format() writes, which is how '¥' stayed unreadable.
    public static function codeForSymbol(string $symbol): ?string
    {
        $found = array_search($symbol, self::SYMBOLS, true);

        return $found === false ? null : $found;
    }

    private function __construct(private BrickMoney $inner) {}

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

    // Brick carries each currency's real minor-unit count; the constant above
    // is the two-decimal assumption every parse boundary in this repo still
    // makes, and JPY has no minor unit at all.
    public function minorUnitsPerMajor(): int
    {
        return 10 ** $this->inner->getCurrency()->getDefaultFractionDigits();
    }

    // A number in major units, for a chart axis. Callers were parsing
    // __toString() to reach it, because Brick may only be named from here.
    public function toMajorFloat(): float
    {
        return $this->inner->getAmount()->toFloat();
    }

    // The same figure exactly, as a decimal string. An fx rate is derived from
    // two of these and lands in a decimal(18,8) column, so the float above
    // cannot serve it -- and a caller outside this file may not reach Brick to
    // get the amount itself.
    public function toMajorString(): string
    {
        return (string) $this->inner->getAmount();
    }

    // The one chart-coordinate seam: a series has to be a number in major
    // units and the divisor is not a hundred everywhere, so a ¥980,000 row was
    // plotted at -9800 beside an axis still labelled in yen. A code no
    // currency table knows falls back to the repo's two-decimal assumption.
    public static function majorUnits(int $minor, string $currencyCode): float
    {
        $money = self::tryOfMinor($minor, $currencyCode);

        return $money === null
            ? $minor / self::MINOR_UNITS_PER_MAJOR
            : $money->toMajorFloat();
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
            return $this->inner->formatToLocale(MoneyText::language()->value);
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
        return MoneyText::ofDecimal((string) $this->inner->getAmount(), $this->currency());
    }

    // Whole units, for a surface with no room for cents: a calendar cell is
    // four characters wide, and a magnitude there beats an exact figure that
    // wraps. Rounds half-up, and never reaches for the minor unit itself —
    // JPY has none, so dividing by a hundred would render a hundredth of it.
    public function formatWholeUnits(): string
    {
        $rounded = $this->inner->getAmount()->toScale(0, RoundingMode::HalfUp);

        return MoneyText::ofDecimal((string) $rounded, $this->currency());
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
