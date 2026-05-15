<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\ValueObjects;

use Brick\Money\Money as BrickMoney;
use Stringable;

/**
 * Immutable money value with a single integer-minor factory entry point.
 *
 * Wraps `brick/money` so domain code never imports brick directly. The only
 * way to construct a Money is `ofMinor(int, string)` — there is no `ofFloat`
 * or `fromString` on purpose: floats and locale-aware string parsing are the
 * exact failure modes the integer-only money handling rules exist to
 * prevent.
 */
final class Money implements Stringable
{
    private function __construct(private readonly BrickMoney $inner) {}

    /**
     * Constructs Money from a signed integer minor-unit amount and an ISO 4217
     * currency code. Negative values represent debits (money out).
     */
    public static function ofMinor(int $minor, string $currencyCode): self
    {
        return new self(BrickMoney::ofMinor($minor, $currencyCode));
    }

    /**
     * Returns a new Money representing this + other. Throws when the
     * currencies differ (brick/money MoneyMismatchException).
     */
    public function plus(self $other): self
    {
        return new self($this->inner->plus($other->inner));
    }

    /**
     * Returns a new Money representing this - other. Throws when the
     * currencies differ.
     */
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

    /**
     * Formats the money for display via ICU's `NumberFormatter` (ext-intl)
     * under the hood. When `$locale` is null, the locale is selected from
     * the Money's currency code: EUR amounts render in Dutch locale
     * (`nl_NL` — comma decimal, symbol with non-breaking space, e.g.
     * `€ 68,86`); every other currency renders in US English (`en_US` —
     * period decimal, symbol prefix without space, e.g. `$74.43`). This
     * matches the calm-aesthetic UI contract: the user reads foreign-
     * currency amounts in the form they'd see on a card statement, while
     * EUR amounts stay in the Dutch convention familiar to a NL user.
     *
     * An explicit `$locale` argument overrides the auto-selection — useful
     * when a caller needs to force a specific locale regardless of
     * currency (e.g. legacy nl_NL-only Blade closures that pre-date the
     * locale-aware default).
     */
    public function format(?string $locale = null): string
    {
        $resolved = $locale ?? ($this->currency() === 'EUR' ? 'nl_NL' : 'en_US');

        return $this->inner->formatTo($resolved);
    }

    public function __toString(): string
    {
        return $this->inner->__toString();
    }
}
