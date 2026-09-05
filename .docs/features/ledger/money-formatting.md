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
well-formed amount of at most two decimals or wider than
`MoneyInput::MAX_WHOLE_DIGITS` whole digits, the ceiling that keeps the
minor-unit multiplication inside a 64-bit int. It hands the system an
`int`, and from there on the invariant holds.

`MoneyInput` also writes the figure back out, for a box the reader is
about to edit — `formatMinor()` and `formatAbsMinor()` — and those take
their group and decimal marks from the same reader locale
`Money::format()` does, through `Locale::groupMark()` and
`Locale::decimalMark()`. They used to be pinned to the Dutch
convention, which put an editable `50,00` in the same budget row as a
read-only `€50.00` for an English reader. **What is displayed must
also parse back**: every mark `formatAbsMinor()` can write —
including the non-breaking space twelve locales group with and
French's narrow no-break space — is stripped again by `tryToMinor()`,
and the round trip is pinned per locale in `MoneyInputTest`. The
parse side stays deliberately tolerant of both separators, because a
reader who has seen the field in one language may type the other.

Nothing else in the tree may format minor units by hand. The one
remaining hand-rolled formatter (`ManagesSplitEditor`, via a private
helper on `TransactionDetail`) wrote Dutch into the split-leg boxes
and now calls `formatAbsMinor()` like everything else.

The machine-readable counterpart is `MoneyInput::toDecimalString()` —
`"1234.56"`, no symbol and no group mark — and it is likewise the only
one. `ReportCsvExporter` and `PromoteStagingToDomain` each carried a
private re-implementation of it with `100` spelled out; both are gone,
and `TaxCsvExporter`'s `toDecimalString(abs($minor))` is the shape a
CSV cell that wants an unsigned figure should copy.

## `MINOR_UNITS_PER_MAJOR` is 100, and that is the fallback, not the rule

A constant only works while every currency in play has two decimal
places. `Currency` declares `Jpy = 'JPY'`, and JPY has **zero** decimal
places — the yen has no minor unit. Anything that builds or renders a JPY
amount by scaling through `Money::MINOR_UNITS_PER_MAJOR = 100` is out by a
factor of a hundred: a parser reading `1000` yen produces `100000` minor,
and `brick/money` — which does know JPY's real scale — renders that as
¥100,000.

Two seams now ask the currency for its own scale rather than reading the
constant, and both fall back to a hundred only for a code no currency table
knows:

- **`Money::majorUnits($minor, $currency)`** — the single chart-coordinate
  seam. `ChartAmount` (Reports), `ForecastChartView`, the forecast
  aggregate-line blade and `RecurringSeriesDetailPage` all route through
  it. `ForecastChartView` already handed the axis formatter its own
  `beatraxCurrency`, so before this the symbol was right and the number was
  a hundredth of itself: the same page printed `-¥980,000` beside a point
  plotted at `-9800`.
- **`MoneyInput`**, which takes the currency as an optional
  `?string $currencyCode` on `tryToMinor()`, `tryToPositiveMinor()`,
  `exceedsMax()`, `formatMinor()`, `formatAbsMinor()`, `decimalPlaces()`
  and `toDecimalString()`, and asks
  `Ledger\Public\ValueObjects\CurrencyScale` for the answer rather than
  computing one. Omitting the code keeps the two-decimal assumption,
  which is what a caller with genuinely no currency in hand wants. At a
  currency's own scale, a zero-decimal currency accepts no fractional part
  at all — `tryToMinor('1250.00', 'JPY')` is `null`, not `125000`.

`CurrencyScale` is where a new caller goes for the scale. It is the only
place the `?? MINOR_UNITS_PER_MAJOR` fallback and the `log10` that turns a
scale into a decimal count are written, and
`OneSeamAnswersTheMinorUnitScaleArchTest` fails the build on a second copy
of either — so a private re-derivation is caught before it can drift. See
[where the scale comes
from](minor-units-and-zero-decimal-currencies.md#where-the-scale-comes-from).

The remaining constant users are the boundaries where no currency is in
scope: three of the four `Ingestion` amount parsers (`IcsAmountParser`
delegates to `MoneyInput` and does none of its own) and the amount
rendering in the `Calendar`, `Tax` and `Onboarding` views. Treat the
hundred there as an assumption the code makes, not a property of money.

## `format()` picks the locale from the reader

`Money::format()` renders an amount for display and takes no argument:
the **reader's active locale** decides how it is written, and the
currency decides only which symbol appears. So the same €1,234.56 reads
`€ 1.234,56` for a Dutch reader, `1.234,56 €` for a German one and
`€1,234.56` in English — separators, grouping and symbol position all
following the language the interface is in.

It did not always. The rule used to be currency-anchored: EUR rendered
in `nl_NL` and everything else in `en_US`, on the reasoning that an
amount should read against its own currency rather than the reader's
language. That is defensible for the symbol, and wrong for everything
else — it gave a German or Spanish reader Dutch symbol placement on
every euro amount, and the requirement is that amounts are formatted
for the user's locale *including symbol position*.

Four seams on `Locale` carry what ICU knows, transcribed so the
no-ICU path below can reach the same answer: `symbolBeforeAmount()`,
`symbolGap()` (a non-breaking space in most languages, nothing in
English and Turkish), `signPrecedesSymbol()` — false only in Dutch,
which writes `€ -1.234,50` — and `minusSign()`, which is U+2212 MINUS
SIGN in Estonian, Finnish, Croatian, Lithuanian, Norwegian, Slovenian
and Swedish, and the ASCII hyphen in the other nineteen.

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

Because `format()` follows the reader, this fails for everyone not
reading in English — twenty-five of the twenty-six shipped languages.
Every rendered amount in the product funnels through this method, so an
uncaught throw is a 500 on any page that shows money.

`format()` therefore catches both exception types and falls through to
`formatWithoutIcu()`, which rebuilds the reader's convention from marks
the repo carries itself — `Locale::groupMark()`, `decimalMark()`,
`symbolBeforeAmount()`, `symbolGap()`, `signPrecedesSymbol()` and
`minusSign()`:

- **English.** Symbol first, no gap, sign leading the whole amount:
  `€1,234.56` and `-$74.43`.
- **Dutch.** Symbol first with a non-breaking space, and the sign
  against the digits rather than in front of the symbol: `€ 1.234,56`,
  `€ -1.234,50`.
- **Most others.** Symbol last, after a non-breaking space:
  `1.234,56 €`.

Grouping is chunked from the right by reversing the digit string,
splitting it into threes and reversing each chunk back; the mark goes in
afterwards, between the restored chunks, so nothing but ASCII digits is
ever reversed. That order is the whole of it: a non-breaking group mark
is two bytes, and `strrev()` over an already-marked string split it in
half, which produced mojibake in every language whose mark is not a
plain space. That was invisible while only Dutch and English drove the
formatter and became reachable the moment the locale did.

The marks, the grouping and the symbol's placement live in `MoneyText`,
not in `Money`: `MoneyInput::formatAbsMinor()` writes the *editable*
figure the reader edits beside the rendered one, so both have to group
the same way. While each class carried its own copy they did not — the
two even disagreed, in their comments, about how many shipped locales
space their thousands. `Money::formatWithoutIcu()` and
`Money::formatWholeUnits()` are now both `MoneyText::ofDecimal()` over
a decimal string `brick` produced.

Every shipped language answers for itself: all twenty-six carry their
own marks, symbol position and sign order on `Locale`, so the fallback
never has to invent a convention it does not know. A currency with no
entry in the class's `SYMBOLS` map renders as its code followed by a
non-breaking space (`CHF 3,850.00`) — which is what ICU itself does for
a currency the locale has no symbol for. The two-decimal scale is
always kept: a whole amount renders `€ 12,00`, never `€ 12`, because a
money column that sometimes shows decimals is harder to scan than one
that always does.

### The invariants the tests hold

`Modules/Ledger/tests/Unit/MoneyFormatTest` pins the parts of this
that are easy to break:

- The fallback output is **byte for byte** what ICU produces —
  separators, grouping, symbol position, the gap, the sign's place and
  the sign's own glyph — asserted by running the same amount through
  both `format()` and `formatWithoutIcu()` on a host that does have
  full ICU data. `formatWithoutIcu()` is public for exactly that
  reason: the device condition cannot be reproduced where the locale
  data is present, and a rendering nothing can reach is a rendering
  nothing checks. Mobile must not read differently from desktop, and if
  the ICU data ever does arrive, nothing about the rendering changes.

  The claim held over six languages for a while and was false in seven
  others. ICU writes U+2212 MINUS SIGN for `et fi hr lt nb sl sv`, the
  fallback wrote the ASCII hyphen, and the dataset covered
  `en nl de tr pt fr` — none of which ICU gives U+2212 — so every
  negative amount in those seven read differently on the phone than on
  the desktop beside it, and no test could see it. The dataset now
  covers all twenty-six.

- **What is deliberately NOT byte for byte is the currency's name.**
  `SYMBOLS` carries one glyph per currency; CLDR carries a name per
  currency *per locale*. ICU writes USD as `US$` in Dutch, `$US` in
  French, `USD` in Polish and `щ.д.` in Bulgarian, and writes EUR as
  `EUR` rather than `€` in Hungarian, Romanian and Ukrainian. Those
  divergences are out of scope by construction, not defects, and the
  twenty-six-language assertion is driven over the currency/locale
  pairs the two sides do agree on — CHF everywhere, since neither side
  has a glyph for it, plus EUR in the twenty-three where ICU writes the
  euro sign.
- The `catch` must not widen into a silent swallow of formatting that
  works. The two paths agree on every currency the product deals in,
  so the proof needs one they cannot: `format()` on an INR amount is
  asserted to produce `₹1,234.56`, the rupee sign ICU knows and the
  transcribed symbol table does not carry.
- Negatives never render in parentheses, in either convention.

## Related

- [`Ledger` architecture](architecture.md) — the module surface these
  amounts move through.
- [Mobile architecture](../mobile/architecture.md) — the ICU data gap
  that forces the fallback, and the other seam (`Fmt::number()`) that
  hits it.
- [`FX` architecture](../fx/architecture.md) — rate handling and
  currency conversion, which operates on the same minor units.
