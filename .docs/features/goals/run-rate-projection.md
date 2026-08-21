# The run-rate projection — window, rate, and finish date

A savings goal shows a target and a total. The question the user
actually has is "when do I get there", and answering it needs a rate.

The cheapest rate available is `contributed / age of goal`, and it is
wrong in both directions. A goal opened with one large deposit a year
ago and untouched since projects a finish date that will never arrive.
A goal opened yesterday with a single deposit divides by a one-day
window and projects a finish next week. Neither reflects what the user
is doing *now*, which is the only thing a projection can honestly
extrapolate.

`Modules\Goals\Public\Services\GoalProjectionService` answers it with a
**trailing-window run-rate**: how much has actually arrived recently,
divided by how long "recently" really was.

## The constants

```php
private const int TRAILING_WINDOW_DAYS = 90;
private const int HORIZON_LIMIT_DAYS   = 90;
private const int MIN_OBSERVATION_DAYS = 7;
```

`TRAILING_WINDOW_DAYS` is how far back the rate is measured.
`HORIZON_LIMIT_DAYS` is how far forward the answer is trusted. They hold
the same value and are separate constants on purpose — they answer
different questions and either can move without the other.

## Computing the rate

`dailyContributionRate()` runs in four steps.

**1. Clamp the window to the goal.** The nominal window start is
`today − 90 days`; the effective start is the later of that and the
goal's own `start_date`, compared as `Y-m-d` strings.

**2. Measure the window that actually exists.**

```php
$elapsedDays = CarbonImmutable::parse($effectiveStart)->diffInDays(CarbonImmutable::today());
```

The divisor is this measured `$elapsedDays`, **not** the constant 90.
Dividing a 30-day-old goal's contributions by 90 would understate its
rate by two thirds and push its finish date three times too far out,
which is exactly the goal a user most wants an answer for.

**3. Suppress a window too short to mean anything.** Below
`MIN_OBSERVATION_DAYS = 7` the method returns `0.0`, which the caller
treats as no projection. Seven days is the point at which a single early
deposit stops dominating the arithmetic: on day two, one €500 deposit
divides by a one-day window into €500/day and projects a €6 000 goal
finished within a fortnight. The card shows building-a-projection copy
instead until enough signal accrues.

**4. Sum the window from the goal's own funding source.** This is the
step that keeps the answer coherent:

- a **pot-linked** goal reads
  `PotBalanceQuery::netMovementForPotSince($potId, $effectiveStart)` —
  the *net* signed sum of that pot's movements, so a withdrawal
  subtracts;
- any **other** goal sums the transactions attributed to it through the
  `goal_contributions` pivot with `transactions.posted_at >=
  $effectiveStart`.

Both are FX-converted into the goal's `target_currency`, which is fixed
at creation and never follows the user's base currency. The rate and the
level therefore always measure the same money in the same unit: a
pot-linked goal whose rate came from attributed transactions would
report progress from one source and speed from another, and could show a
rising total with a zero rate indefinitely.

The rate itself is `windowSum / max(1, $elapsedDays)` — a float in minor
units per day. The `max(1, …)` is a division guard only; the
seven-day floor has already rejected every window it could apply to.

## From rate to date

`project()` composes it:

```php
if ($contributedMinor >= $goal->target_minor) return NO_PROJECTION;
if ($dailyRateMinor <= 0.0)                   return NO_PROJECTION;

$remainingMinor = $goal->target_minor - $contributedMinor;
$daysToFinish   = (int) ceil($remainingMinor / $dailyRateMinor);

return [
    'date'          => CarbonImmutable::today()->addDays($daysToFinish)->format('Y-m-d'),
    'beyondHorizon' => $daysToFinish > self::HORIZON_LIMIT_DAYS,
];
```

Three cases return no date at all, and they are distinct: a goal already
at or past its target has nothing to project; a goal with no history in
the window has no rate; a goal whose net movement in the window was zero
or negative — money came back out — would project a finish in the past
or never, so it reports nothing rather than a lie.

`ceil()` rounds toward the honest answer. A remaining €10.00 at €4.00 a
day is 2.5 days, and reporting three days is right; reporting two would
promise a finish before the money can arrive.

`beyondHorizon` does not suppress the date, it labels it. Past 90 days
the extrapolation is carrying a quarter's worth of behaviour forward
into a year, so the renderer marks it lower-confidence rather than
hiding an answer the user asked for.

## Worked example

A €500,00 goal (`target_minor = 50000`) started 40 days ago, with
€200,00 contributed, all inside the last 40 days.

- `windowStart` = today − 90; `effectiveStart` = the goal's start, 40
  days ago, because it is later.
- `elapsedDays` = 40, which clears the 7-day floor.
- `windowSum` = 20000 minor; rate = `20000 / 40` = 500,00 minor/day.
- `remaining` = 50000 − 20000 = 30000; `daysToFinish` =
  `ceil(30000 / 500)` = 60.
- Result: a date 60 days out, `beyondHorizon = false`.

Halve the contributions and the rate halves to 250/day, `daysToFinish`
becomes 120, and the same date arithmetic now returns
`beyondHorizon = true`.

## What is not consulted

The `Forecasting` module is not involved. A `ForecastDto` point is an
account's overall balance trajectory, and a goal has no account — its
progress comes from a linked pot or from explicitly attributed
transactions, both of which belong to exactly one goal.

## See also

- [`architecture.md`](architecture.md) — the contribution model,
  `target_currency` immutability, and the ownership rules.
- [`../pots/over-allocation-guard.md`](../pots/over-allocation-guard.md)
  — the write path behind the pot movements this rate reads.
