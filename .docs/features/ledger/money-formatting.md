# `Ledger` — money representation and formatting

Every amount in the product is an integer, and every amount the user
sees is rendered by one class. `Money` is the wrapper that holds both
ends of that: it is the only sanctioned way to carry an amount around
the domain, and `Money::format()` is the only sanctioned way to turn
one into a string. This page explains the invariant it defends, the
two-decimal assumption baked into it, and the ICU-less rendering path
that exists because the mobile build cannot format a Dutch amount.

## The integer invariant, and why the obvious API is missing

Money in a float is wrong in a way that does not announce itself. A
`0.1 + 0.2` rounding error survives an import, a split, a reconcile
and a year-end tax export, and only shows up as a total that is one
cent off with no row to blame. So the ledger never holds a decimal
amount: every column is `*_minor`, an integer count of minor units,
and arithmetic happens on integers.

`Money` is a thin wrapper over `brick/money` that exists to keep
`brick` out of domain code and to make the invariant unbypassable.
There is exactly one constructor:

```php
Money::ofMinor(int $minor, string $currencyCode): self
```

Sign carries meaning: negative is a debit (money out), positive is a
credit (money in). There is no `ofFloat()` and no `fromString()`, and
their absence is deliberate rather than an oversight — `brick/money`
offers both. A float constructor reintroduces exactly the error the
integer columns exist to prevent, and a string constructor drags in
locale-aware parsing at a point where the caller has no idea which
locale the string came from ("1.234" is a thousand in Dutch and one
and a bit in English).

String parsing does have to happen somewhere; it happens at the input
boundary, in `MoneyInput`, which is the deliberate counterpart to this
refusal. `MoneyInput::tryToMinor()` accepts the plain (`12.50`),
Dutch-grouped (`1.234,56`) and comma-decimal (`12,50`) forms, treats
whichever of `.` or `,` appears rightmost as the decimal separator,
and returns `null` — never a guess — for anything that is not a
well-formed amount of at most two decimals. It hands the system an
`int`, and from there on the invariant holds.

## `MINOR_UNITS_PER_MAJOR` is 100, and that is a real limitation

`Money::MINOR_UNITS_PER_MAJOR = 100` is the scale factor every parse
and format boundary in the repo multiplies or divides by: the four
`Ingestion` amount parsers, `MoneyInput`, `CashBookPage`, and the
amount rendering in the `Calendar`, `Tax`, `Forecasting`, `Reports`
and `Onboarding` views.

A constant only works while every currency in play has two decimal
places. `Currency` already declares `Jpy = 'JPY'`, and JPY has **zero**
decimal places — the yen has no minor unit. Anything that builds a
JPY amount by scaling through this constant is therefore out by a
factor of 100: a parser reading `1000` yen produces `100000` minor,
and `brick/money` — which does know JPY's real scale — renders that as
¥100,000.

Making this correct means asking the currency for its own scale
instead of reading a constant, at every one of the call sites above.
Until that happens, treat 100 as an assumption the code makes, not a
property of money.

## `format()` picks the locale from the currency

`Money::format(?string $locale = null)` renders an amount for display.
With no argument it chooses the locale from the currency: EUR renders
in `nl_NL` and everything else in `en_US`, so a foreign amount reads
the way it would on a card statement while EUR stays in the Dutch
convention the rest of the UI uses. An explicit `$locale` argument
overrides that choice, and is what the tests use to force a path.

Internally this calls `brick/money`'s `formatToLocale()`, **not**
`formatTo()`. The two do the same thing — `formatTo()` forwards to
`formatToLocale()` — but `formatTo()` was deprecated in `brick/money`
0.11 and removed in 0.14. While it existed it raised a deprecation
notice on every rendered amount, which on a dashboard is hundreds per
page load.

## The no-ICU fallback

`formatToLocale()` constructs a PHP `NumberFormatter`, which needs ICU
locale data for the locale it is given. **The mobile build does not
have Dutch locale data.** The bundled PHP binaries ship an ICU data
package filtered to English only, so `ext-intl` loads and reports a
version, but constructing a formatter for `nl_NL` fails — as an
`IntlException` when intl error-exceptions are enabled, and as a
`ValueError` from the constructor otherwise. The full story of how the
data goes missing, and why it cannot simply be added back, is in
[the mobile architecture page](../mobile/architecture.md) under
"`--with-icu` ships ICU code, not ICU locale data".

Because `format()` is currency-anchored, this fails asymmetrically and
confusingly: on device, USD and GBP amounts formatted fine while every
EUR amount — the overwhelming majority — threw. Every rendered amount
in the product funnels through this method, so an uncaught throw is a
500 on any page that shows money.

`format()` therefore catches both exception types and falls through to
`formatWithoutIcu()`, which reproduces the two conventions this class
anchors on from group and decimal marks the repo carries itself
(`Locale::groupMark()` and `Locale::decimalMark()`):

- **Dutch (EUR).** Symbol first, separated by a non-breaking space,
  and the sign sits against the digits rather than in front of the
  symbol: `€ 1.234,56`, and `€ -1.234,50` when negative.
- **US English (everything else).** The sign leads the whole amount,
  symbol included: `-$74.43`.

Any locale that is neither Dutch nor English resolves to one of those
two by currency, so the fallback never has to invent a convention it
does not know. A currency with no entry in the class's `SYMBOLS` map
renders as its code followed by a non-breaking space
(`CHF 3,850.00`) — which is what ICU itself does for a currency the
locale has no symbol for. The two-decimal scale is always kept: a
whole amount renders `€ 12,00`, never `€ 12`, because a money column
that sometimes shows decimals is harder to scan than one that always
does.

### The invariants the tests hold

`Modules/Ledger/tests/Unit/MoneyFormatTest` pins the parts of this
that are easy to break:

- The fallback output is **byte for byte** what ICU produces, asserted
  by formatting the same amount twice on a host that does have full
  ICU data — once through a locale ICU accepts, once through a
  deliberately invalid one. Mobile must not read differently from
  desktop, and if the ICU data ever does arrive, nothing about the
  rendering changes.
- The `catch` must not widen into a silent swallow of formatting that
  works. `format('de_DE')` is asserted to produce `1.234,50 €` —
  symbol last, which the fallback cannot produce — so a catch that
  started eating successful cases would fail the test.
- Negatives never render in parentheses, in either convention.

## Related

- [`Ledger` architecture](architecture.md) — the module surface these
  amounts move through.
- [Mobile architecture](../mobile/architecture.md) — the ICU data gap
  that forces the fallback, and the other seam (`Fmt::number()`) that
  hits it.
- [`FX` architecture](../fx/architecture.md) — rate handling and
  currency conversion, which operates on the same minor units.
