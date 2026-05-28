# ADR 0009 — brick/money for multi-currency arithmetic

- **Status:** Accepted
- **Date:** 2026-05-27
- **Graduated from:** Phase 17, decision D-32

## Context

beatrax handles money in multiple currencies. Google Play receipts
arrive in USD. ICS Cards settles foreign-currency charges from many
countries — Stripe-billed merchants quote USD, hotel chains quote GBP,
European retailers stay in EUR. The system has to preserve both the
original-currency amount and the EUR-settled amount, present them
side by side, sum cash-flow projections per currency, and never
silently lose FX information.

PHP's two default tools — `float` and integer-as-cents — both fail this
job:

- **`float`** corrupts arithmetic silently at the second decimal place.
  Two USD 19.99 charges added back together don't always come back as
  USD 39.98. For arithmetic the user audits against their bank
  statement to the cent, floats are unacceptable.
- **Integer cents** works for a single-currency app. The moment a
  second currency lands, "amount in cents" stops being a complete
  representation — you need the currency code on the same value, and
  every addition has to refuse to mix two currencies. Manual
  enforcement of that across every Eloquent model becomes a sprawl of
  defensive checks.

`moneyphp/money` is the long-established choice and arrives in the
project transitively via `genkgo/camt`. `brick/money` is the newer
library — immutable Money objects, exact arithmetic via `brick/math`
(no BCMath required), explicit rounding, currency conversion via
pluggable rate providers, MoneyBag for multi-currency totals, modern
PHP 8.2+ type signatures.

The two coexist in the lock file (`brick/money` for app code,
`moneyphp/money` carried in by `genkgo/camt` for the CAMT.053
parser's internal representation). Converting at the CAMT boundary is
trivial — pull amount and currency out of the parser's value object,
construct a `brick/money` `Money` instance, never look at the parser's
value type again.

## Decision

Every monetary value in beatrax is a `Brick\Money\Money` instance once
it crosses the parser boundary. Specifically:

- **Domain code** uses `Money::ofMinor(int $minor, string $currency)`
  or `Money::of(...)` for construction; `plus()`, `minus()`,
  `multipliedBy()` for arithmetic; `formatTo($locale)` for display.
- **DTOs** carry `Money` instances directly, not separate
  `amount_minor` + `currency` fields.
- **Eloquent models** cast persisted columns through a `MoneyCast` cast
  that reads `amount_minor` + `currency` columns and returns a `Money`
  instance to the caller. The columns themselves stay as `INTEGER` +
  `VARCHAR(3)` — SQLite knows nothing about Money; the cast is the
  boundary.
- **Multi-currency totals** use `Brick\Money\MoneyBag` rather than
  attempting to sum into a single currency. A "monthly total"
  presented to the user shows EUR 1,234.56 + USD 19.99 as two lines,
  not as a rate-converted aggregate. Conversion only happens when the
  user explicitly asks ("view all in EUR"), and uses
  `Brick\Money\ExchangeRateProvider` with rates stored in a per-user
  rate table.
- **CAMT.053 boundary** converts `moneyphp/money` instances into
  `brick/money` instances inside `Modules/Import/Internal/Parsers/`.
  The rest of the codebase only sees `brick/money` types.

The [`MoneyColumnsArchTest`](#) arch invariant enforces that every
Eloquent model with a column matching the `*_minor` naming convention
casts through `MoneyCast`. The [`noFloatMoneyArchTest`](#) invariant
forbids `float` parameter or return types on any service that touches
money.

## Consequences

- **Arithmetic is exact.** Adding two `Money::ofMinor(1999, 'USD')`
  instances always returns `Money::ofMinor(3998, 'USD')`. No rounding
  surprises.
- **Currency mixing throws.** `$usd->plus($eur)` throws
  `MoneyMismatchException` rather than silently producing a nonsense
  total. The exception is surfaced at the service layer, not silently
  swallowed.
- **FX information is preserved.** A USD charge settled into EUR keeps
  both representations in the database — the original-currency amount
  on the transaction, the settled EUR amount on the ICS card-statement
  row. The chain-resolution layer can show the user "you paid USD
  19.99, which cost you EUR 18.42" without recomputing the FX rate.
- **Display is locale-aware.** `Money::formatTo($user->locale)` handles
  the comma-vs-period decimal-separator and the symbol position for
  the user's locale.
- **Two money libraries in the lock file.** `moneyphp/money` arrives
  via `genkgo/camt`. The coexistence is bounded — no application code
  imports `moneyphp/money`; conversion at the CAMT boundary keeps
  the surface small.

## Alternatives considered

- **Stick with `moneyphp/money` throughout.** Rejected: `brick/money`
  has better immutable semantics, exact `brick/math` arithmetic
  without BCMath, and MoneyBag for multi-currency totals. The
  `genkgo/camt` dependency on `moneyphp/money` does not force the
  rest of the codebase to share that choice.
- **Integer cents plus a manual currency column.** Rejected: the
  arithmetic-safety story across multi-currency was hand-rolled
  for every addition and every comparison. Defensive checks would have
  out-grown the library cost in a month.
- **Floats with explicit rounding at display.** Rejected: rounding at
  display does not undo the corruption that floating-point arithmetic
  introduces during accumulation.

## Related

- [ADR 0005 — SQLite with WAL](0005-sqlite-wal.md) — the storage
  layer; Money is cast to `INTEGER` + `VARCHAR(3)` columns there.
- [Architecture — Data model](../architecture/data-model.md) — the
  column-naming convention enforced by `MoneyColumnsArchTest`.
