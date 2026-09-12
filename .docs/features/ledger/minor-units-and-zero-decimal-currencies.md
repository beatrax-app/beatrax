# Minor units and zero-decimal currencies

Every amount in the ledger is stored as an integer count of **minor units**.
For EUR, USD and GBP that is 1/100 of the major unit, so `12345` is
`€123.45`. For JPY there is no subdivision at all: the minor unit *is* the
yen, so `1000` is `¥1,000` — not `¥10.00`.

## Where the scale comes from

There is no scale enum. Brick\Money is the source of truth, reached through
one method:

- `Money::minorUnitsPerMajor()` — `10 ** getDefaultFractionDigits()`. **The**
  definition.
- `Money::MINOR_UNITS_PER_MAJOR = 100` — the fallback for a code Brick does
  not recognise.
- `Ledger\Public\ValueObjects\CurrencyScale` — the one seam over those two,
  and the only place the `?? MINOR_UNITS_PER_MAJOR` fallback and the `log10`
  that turns a scale into a decimal count are written. `MoneyInput` reads it,
  and so do the ingestion amount parsers, the forecasting amount parser and
  the recurring ranking query.

The question and its `log10` used to be written out per class, so
`OneSeamAnswersTheMinorUnitScaleArchTest` fails the build on a second copy
of either.

`currencies.minor_unit` is reference data; nothing reads it to do arithmetic.
It is seeded from `CurrencyScale` itself rather than from a literal, so the row
and the arithmetic cannot disagree.

**The scale is not ICU's to decide.** ICU 78 dropped HUF and IDR to zero
fraction digits where ICU 77 and ISO 4217 give them two; the bundled desktop
binary is ICU 77.1 and a build host may be on either. A scale read from ICU is
one that moves when a system library is upgraded, and what a stored integer
means cannot move — so it comes from the ISO 4217 data `brick/money` carries
and `composer.lock` pins. `brick`'s locale formatter pins the fraction digits
to the amount's own scale before ICU sees the number, so the rendered figure
follows the same source.

## Why a hardcoded ÷100 is a bug, not a shortcut

Any code that divides by 100 to render, or multiplies by 100 to parse, is
correct for EUR and silently wrong for JPY. It is wrong by a factor of one
hundred, in money, and it reads as a plausible number — which is why these
defects survive review. The recurring shapes:

- **Reading a stored amount** into a form field: a ¥1,000 charge prefilled as
  `10,00`.
- **Parsing a typed amount** back: `600` + `400` against a ¥1,000 parent read
  as ¥600.00 + ¥400.00, so the split refuses as over-allocated by ¥99,000.
- **Charting or exporting**: a ¥1,000 row plotted at 10, or exported as
  `10.00`.
- **A filter chip** that formats with one scale beside a query that filters
  with another, so the label and the list disagree.

Pass the currency. `MoneyInput::toDecimalString(int $minor, ?string $code)`
keeps the old ÷100 when the code is null, so a caller that genuinely does not
know its denomination is unchanged — but a caller that does know and omits it
is the bug.

## The other half: comparing two denominations as bare integers

A hardcoded ÷100 is the visible half. The other half needs no literal at
all — it is a single integer standing in for an amount, met by a row in a
different money:

- **A threshold or bound.** A rules-engine amount condition, a search
  amount filter, an income-detection floor: each is one number the reader
  typed in their own currency, and each was compared straight against
  `settled_amount_minor` of every row. A bound written as EUR 50.00
  (`5000`) fires on a JPY 5,001 charge worth about EUR 31.
- **A product constant met by a reader-scoped figure.** The same defect
  with nobody to have typed it: `SavingsInsightsQuery`'s review floor was
  the integer `500` compared against a monthly cost already converted into
  the reader's *reporting* currency. Both sides were in one money, so the
  comparison was well formed — and still wrong, because the floor meant
  EUR 5.00 to one reader and about EUR 3.00 to a reader reporting in yen.
  A yen is roughly 0.6 of a eurocent, so the error here is a factor of
  about 1.7, not of 100 — and rescaling the constant by
  `CurrencyScale::minorUnitsPerMajor()` would have read it as five major
  units, JPY 5, roughly two orders of magnitude *further* from the
  intended floor than the bug. Scale is what a figure is rendered and
  parsed at; a threshold is an amount, and an amount converts.
- **An ordering key.** `ORDER BY ABS(monthly_equivalent_minor)` put JPY
  10,000 a month (`10000`, about EUR 63) above EUR 99 a month (`9900`) on
  a list headed "biggest first".
- **A quoted rate.** A rate rendered at a fixed number of decimals loses
  its significant digits when the pair is small: EUR-per-JPY is 0.00628536
  and four places wrote 0.0063, which no longer reaches the converted
  figure printed beside it. `Rate::forDisplay()` keeps three significant
  digits instead.

  Three is right for a rate read *at a glance*, beside a figure it is
  only meant to explain the size of. It is not enough where the reader
  came to check the rate: 0.00629 does not reproduce the EUR 3,016.97
  the figure was priced at. `Rate::exact()` answers that reading — the
  column's own eight places with trailing zeros trimmed — and it is what
  `x-core::fx-disclosure` prints. `Rate::parse()` is the reader of both
  shapes a rate arrives in, the column's decimal and the exact rational
  brick/money derives a cross-rate as.

Two rules follow, and both are already how the rest of the app behaves:

1. **A bound can only test rows in the currency it was written in.** Two
   ways satisfy that, and which one is right depends on whether the rows
   in the other currencies are supposed to be in the answer. Scoping the
   query to the bound's own currency is the cheaper one and is what a
   single-currency question wants. Restating the bound — converting the
   threshold once per currency and testing each currency's rows against
   its own restatement — is what a question whose answer spans currencies
   wants, and `FX::CrossCurrencyBound` is the one place that conversion
   is written. Either way a currency the rate table cannot reach states
   the bound in neither, and is left out and named rather than counted at
   one to one, the same choice `CrossCurrencyTotal` makes for a bucket.

   Scoping where restating was needed is its own defect, and a quiet one:
   the report figure and the transaction list it drills into ran the two
   different rules, so a row reading EUR 54.00 over one EUR 30.00 and one
   USD 30.00 charge opened a list showing EUR 30.00. Both surfaces were
   internally consistent and the reader had no way to tell which number
   to believe. `ReportAggregator` and `SearchQuery` now restate through
   the same collaborator. A categorisation rule's amount condition still
   scopes — see [the rule condition](#the-rule-condition-which-reads-as-an-exception-and-is-not-one)
   below — because it asks whether one row matches a threshold the reader
   writes in one money, not for a set that spans several.
2. **A ranking has to be converted first.** Where the sort has to stay in
   SQL, each currency carries a multiplier into the reader's money: the
   rate, times the ratio of the two minor-unit scales. Bind the comparison
   value as an **integer** — PDO binds a PHP float as a string, and SQLite
   sorts every number below every string, so a float bound turned a keyset
   cursor into "always true".

A constant that is merely *re-read* in another currency is a different
thing and is not this bug: a settlement tolerance of `500` compares a
statement against a payment already proven to be in the same money, so it
shifts the slack rather than comparing unlike quantities.

## Why JPY is seeded

`Currency::Jpy`, `Money`'s `¥` symbol and the ICS amount parser's accepted
code list all carried JPY, but the `currencies` table did not. That table is
what `base_currency` and `SetAccountCurrency` validate against, so the one
denomination that would expose a wrong scale could not be selected, and no
demo dataset or device test could reach those paths. It is seeded by
`CurrenciesSeeder` and by a migration, because a device that joins a household
by pairing never runs the installer.

The demo dataset carries a JPY account and JPY rows for the same reason: a
sweep on EUR-only data cannot tell a fixed scale from a broken one. It carries
**two** JPY accounts, because the first is a card, and a card holds no
allocatable balance — so pots, pot-funded goals and the cash book were all
unreachable in a zero-decimal currency until a yen cash account existed.
[What the demo zero-decimal account has to show](what-the-demo-zero-decimal-account-has-to-show.md)
is the per-feature list.

## The box has to invite the shape it accepts

A refusal that arrives after the figure is typed is the second-best outcome.
The first is a field that never invited the refused shape, and that is three
attributes plus one glyph, all of which must follow the *same* currency the
parser behind the field reads at:

- **`placeholder`** — `MoneyInput::formatAbsMinor(0, $code)`. Scale from the
  currency, marks from the reader: `0` for JPY, `0.00` for an English EUR
  reader, `0,00` for a Dutch one. `toDecimalString()` is the machine form and
  always writes a period, so it is the wrong tool for a placeholder.
- **`inputmode`** — `decimal` where `MoneyInput::decimalPlaces($code) > 0`,
  `numeric` where it is `0`. A yen keyboard has no separator key to offer.
- **`step`** on a `type="number"` bound — `0.01` or `1` by the same test. The
  amount-range filters step by the reader's reporting currency, which is what
  `SearchQuery::applyAmountFilters()` parses the bound at.
- **The symbol beside the box** — `Money::symbolFor($code)`. A pinned `€` over
  a yen figure names the wrong money.

A shape *gate* written beside the parser is the same defect one step earlier.
`SearchQuery` and `QueryParser` each tested a typed figure against a
hand-written `\d{1,2}` fraction before handing it to `MoneyInput`: a yen
reader's `"12.50"` cleared the gate, failed the parse behind it, and the null
was read as an amount of zero; a dinar's `"12.500"` was truncated to `12.50`
by the token regex, a hundredth of what was typed. Where a parser exists, it
is the gate — and where a regex genuinely has to spell the fraction, its width
comes from `MoneyInput::decimalPlaces()`.

An example figure inside a sentence follows the same rule: build it from
`MoneyInput::formatAbsMinor($minor, $code)` rather than writing one number into
26 locale files. One hand-written `1.250,00` served every locale on the
opening-balance field, so an English reader was shown a Dutch figure and a yen
account an amount its own parser refuses.

### Which currency, per field

Not every amount box is denominated in the reader's base currency, and getting
this wrong is worse than the placeholder it fixes:

| Field | Denomination |
|---|---|
| Cash-book amount | the cash account's `default_currency` |
| Reconcile statement balance | the selected account's currency |
| Split leg | the transaction's `settled_currency` |
| Pot create / fund / withdraw / move | the pot's currency, from its account |
| Goal target | the goal's immutable `target_currency` |
| Envelope assign / move | the reader's base — the whole fold converts into it |
| Forecast buffer, opening balance | the account's own currency |
| Scenario one-off / recurring | the code the form's own currency select carries |
| Scenario new amount, what-if | the series' `latest_currency` |
| Amount-range filters | the reader's base |
| Rule amount condition | the reader's base — the matcher tests only rows that settled in it |

Half of that table reads "the account's currency", so where an account's
`default_currency` comes from is the same question one level down. It is the
file the account was opened from, never the reader's reporting currency —
[an account is denominated by its statement](../import/an-account-is-denominated-by-its-statement.md).
A yen label on a euro account is this defect at its worst: the field refuses
`2158.91` outright rather than reading it at the wrong scale.

### The rule condition, which reads as an exception and is not one

A categorisation rule's amount condition looks currency-blind and is not.
`MapsRuleRows` parses and re-renders the threshold at `BaseCurrency::value()`,
and `RuleEngine::conditionMatches()` tests an amount condition only against
rows whose `settled_currency` is the reader's own — the first of the two rules
above, applied. So the box follows that same money: `inputmode` and the
placeholder come from `MoneyInput` at the reader's base, like every other
amount field on the list.

That is a scope, not a denomination stored on the condition: a reader who
switches reporting currency is writing thresholds in the new one against rows
that settled in it. A rule that has to fire on a fixed foreign amount needs a
currency of its own on the row first.

The field that looks like a candidate and is not: the onboarding
starting-balance card takes a count of **minor units** (its suffix says so), so
a whole number is right for every currency.
