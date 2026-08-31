# `Goals` — architecture

The `Goals` module lets a user track a savings goal (a target amount by a
target date), funded either by a linked savings pot or by the transactions
the user explicitly attributed to it, and projects when it will finish based
on recent contribution behaviour.

## Module boundary

- **Public/Services/GoalProgressQuery** — the read model for `/goals` and the
  dashboard summary card: active/completed goals (`forUser()`) and archived
  goals (`archivedForUser()`), each with contribution progress and a
  projected finish date.
- **Public/Services/GoalProjectionService** — the run-rate projection
  algorithm, consumed only by `GoalProgressQuery`.
- **Public/Services/GoalWriter** — the sole write path: create, update, and
  the three lifecycle transitions (`markComplete`, `archive`, `restore`). Name,
  amount and target date are all validated here rather than only on the form,
  because a migration reaches `save()` with no form in front of it.
- **Public/Services/GoalContributionWriter** — the sole write path for the
  `goal_contributions` pivot: `attribute()` and `detach()`.
- **Public/Services/GoalContributionQuery** — the picker list
  (`attributableGoals()`) and the per-transaction attribution list
  (`forTransaction()`) the Ledger transaction detail screen renders.
- **Public/Dto/GoalProgressRow** — one goal's progress row.
- **Public/Dto/GoalAttributionRow** — one (goal id, goal name) pair for the
  attribution picker.
- **Public/Exceptions** — `GoalNotFoundException` (missing/cross-user),
  `InvalidGoalNameException` (blank name) and `InvalidGoalAmountException` (bad
  amount), distinct types so callers drive control flow on exception identity
  rather than message text.
- **Internal/Http/Livewire** — `GoalsPage` (`/goals`) and `GoalsSummaryCard`
  (dashboard tile).

## Contribution progress

A goal's `contributedMinor` comes from exactly one of two sources, both of
which belong to a single goal:

- **Linked pot** (preferred when present): the pot's current balance,
  FX-converted into the goal's `target_currency` when the pot's currency
  differs.
- **Otherwise**: the sum of the transactions the user attributed to this goal
  through the `goal_contributions` pivot, each converted into
  `target_currency`. A goal nothing is attributed to reports 0 contributed.

Both sources are batch-loaded once per render — never a per-goal follow-up
query, and both convert through `CrossCurrencyTotal`: the level and the rate
share one rate lookup per (source currency, `target_currency`) pair for the
whole list rather than one per attribution, and a currency the rate table
cannot reach is left out of the figure rather than added at one to one. A goal
funded only in a currency with no rate therefore reads 0 contributed and is
never marked reached on minor units that are not its own.

What was left out is **named**, not merely dropped. `GoalProgressRow` carries
the `unconverted` codes straight off the `ConvertedTotal`, and both goal lists
render `core::money.not_converted` beside the money line when
`isPartial()`. Goals and Pots were the only money surfaces in the app that
dropped an unpriceable currency in silence; `ThisPeriodAtAGlanceQuery`, both Tax
queries and `CounterpartyIndexRow` had carried the codes through to that same
line all along.

There is deliberately no third source. Goals used to sum every credit on a
linked `goals.account_id`, which belonged to no goal in particular: two goals
over one account showed the same figure, and any target below a month's income
read as reached the moment the salary landed. The column and its sum are gone.

An attribution counts whenever the transaction posted. `start_date` bounds the
projection's observation window only — an explicit attribution is the user's
own statement that the money funds the goal, so a backdated one is not silently
dropped.

A goal with an active linked pot takes its whole figure from that pot, so an
attribution to one would be discarded on the next render. It is refused at both
ends rather than accepted and dropped: `attributableGoals()` leaves those goals
out of the picker, and `GoalContributionWriter::attribute()` returns false for
one, because the picker is a page and the writer is reachable from the browser.
Archiving the pot puts the goal back in the picker. The discarding version
shipped: EUR3.850,00 attributed, a bar that did not move, and a transaction
screen that listed the attribution as a fact.

`goal_contributions` is append-only and holds no amount of its own: the funded
figure is always read back through the joined transaction, so an edited or
FX-restated amount cannot drift from its attribution. `unique(goal_id,
transaction_id)` makes attributing twice — by double-submit or by a replayed
peer op — a no-op. The pivot shape (rather than a `goal_id` column on
`transactions`) keeps the hot, heavily-synced transactions table untouched and
already allows one transaction to fund several goals.

`target_currency` is fixed at goal creation and never changes — it is
intentionally NOT the user's current base currency, since a user may change
their base currency after a goal exists. Both the contribution sum and the
target figure are always expressed in this same fixed currency, so
`fractionComplete` and the "reached" transition are internally consistent
even if the user's base currency later diverges.

`fractionComplete` is a plain float ratio of two integer minor-unit amounts —
`contributedMinor / targetMinor`, computed in `GoalProgressQuery` and never
passed through `Money` at all. There is no float accessor on `Money` to reach
for and there never has been one: it exposes `toMinor()` and its formatters,
and `ofMinor()` is the only constructor — the class has carried "there is no
`ofFloat`" in its own docblock since it landed.

The practice the name suggested is what got banned, and it got banned because
it shipped. Two arch tests hold the line, both pointing at
[invariants-from-shipped-failures](../../conventions/invariants-from-shipped-failures.md).
`NoFloatMoneyArchTest` keeps money out of a float in the schema — no
`float`/`double`/`real` amount column, every `decimal` column cast to
`string` so it cannot reach `brick/math` as a float.
`MoneyNeverPassesThroughFloatArchTest` keeps it out of a float in code — no
amount rendered through `number_format()`, no minor-unit value derived by
multiplying a float by 100, no `(float)` cast of a typed amount (PHP reads
`"1.234,56"` as `1.234`, a silent hundredfold error).

A ratio is not a money value and stays legible under both rules: it lands in
neither a minor-unit name nor a `(float)` return, which is the same carve-out
`MoneyNeverPassesThroughFloatArchTest` documents for chart coordinates. A
currency amount stays an integer count of minor units end to end.

`GoalProgressRow` derives what every goal surface asks of it:
`percentComplete()`, `barWidth()`, `isCompleted()`, `isPartial()` and the one
`DATE_FORMAT` all three surfaces render a date with. They are on the row rather
than at the call site, because two lists computing the sliver rule under two
names is how the two lists came to disagree — and the dashboard tile, which
kept its own copy, was still printing `24 Feb '27` where `/goals` printed
`24 Feb 2027`.

`percentComplete()` **floors**, and is floored at 0 as well as capped at 100.
Rounding reached 100 from below: EUR4.995,00 of EUR5.000,00 drew a completely
full bar under `aria-label="…100% complete"` while the state was still
`in_progress` and the reader was five euro short. Flooring at 0 is the other
end — an attributed spend is a withdrawal, so the sum can go negative, and a
progressbar declaring `aria-valuemin="0"` may not then report -4. The money line
carries the negative instead.

`barWidth()` decides the 2% sliver on the **fraction**, not on the percentage.
Asking the percentage whether anything was there answered no for every goal
under 0.5%, because that is exactly the share the percentage floors away — so
the rule that exists to draw a visible mark for a real contribution drew
nothing for the goals that needed it most.

## Projection algorithm

`GoalProjectionService::project()` estimates a finish date from a
**trailing-window contribution run-rate**:

1. A goal already at or past its target gets no projected date.
2. The contribution sum over the trailing 90 days (clamped to the goal's own
   `start_date` if younger) is divided by the *actual elapsed observation
   window* — not the constant 90 — so a goal younger than the trailing
   window doesn't have its rate systematically understated. The sum is read
   from whichever source the goal's progress comes from: pot movements for a
   pot-linked goal, attributed transactions otherwise. Rate and level always
   measure the same money.
3. A goal with fewer than 7 days of observation history is suppressed
   entirely (no projected date) — dividing a single early deposit by a
   1–2 day window would extrapolate a misleadingly-soon finish.
4. A zero-or-negative daily rate past that threshold also suppresses the
   projection, but it is a **different** state and the card says so:
   `stalled: true`. The two reasons are not interchangeable. A goal
   started six months ago with €500 contributed four months ago has
   ninety days of observation and an empty trailing window — "Not enough
   history" is a definite claim and a false one there, so that case reads
   `projection.no_recent_contributions` and only the younger-than-7-days
   case reads `projection.not_enough_history`.
5. The projected date is `today + ceil(remaining / dailyRate)`; beyond 90 days
   it is reported as extrapolated / lower-confidence (`beyondHorizon: true`).
6. A rate of a few cents a day answers past a century, and past `PHP_INT_MAX`
   the int cast wraps and `addDays()` walks BACKWARDS. Clamping the walk to
   36 500 days replaced a date twenty years in the past with a date the
   arithmetic never produced, printed under "Est." with the same confidence as
   one it did. Past that bound there is **no date at all**, flagged
   `beyondHorizon`, and the card says so in its own sentence.

`today` is read **once**, by `GoalProgressQuery`, and threaded into every
branch. It used to be read about seven times per goal per render — twice inside
`observedDays()` alone — so a render that straddled midnight measured one
goal's observation window against yesterday and dated the next one from today.

The chain that turns those states into a sentence lives once, in
`Resources/views/partials/goal-projection-line.blade.php`, and both the phone
list and the desktop card include it with their own class string. Its branches
are ordered by the state the projection reported, never by the level: asking
"is the sum zero" first collapsed three different reasons for having no date
into `projection.add_contributions`, which is a false sentence for two of them.
A goal whose attributions net out to a withdrawal has contributions and is told
so; a goal younger than the observation floor is told its history is short; only
a goal with nothing attributed at all — `hasContributions` false, which is the
existence of a row, not a non-zero sum — is asked for a first one. The phone
list used to carry a second copy of the chain that had lost the
`projection.projection_note` qualifier on the `beyondHorizon` branch, so the
same estimate read as a hard date at phone width and as an estimate on the
desktop. `Resources/views/partials/goal-target-date.blade.php` is the same
arrangement for the target date the create form refuses to omit.

The trailing-window length and the horizon limit are both 90 days by design.
Forecasting is not consulted: a `ForecastDto` point is an account's overall
balance trajectory, and a goal no longer has an account.

## Ownership and lifecycle safety

Every `GoalWriter` mutation re-asserts ownership against the **explicitly
passed** `$user`, bypassing the `BelongsToUser` global scope rather than
relying on it alone — the global scope reads the ambient `CurrentUser` and
is a no-op in an unauthenticated context (e.g. a queued job), so trusting it
alone for an ownership check is a latent cross-user access path. A missing
or foreign goal id resolves to `null` and the caller either throws
(`update()`) or silently no-ops (the three lifecycle methods, matching the
project's read-or-no-op convention for background-safe mutations).

`status` has exactly one write surface: the three dedicated lifecycle
methods (`markComplete`, `archive`, `restore`). `save()` and `update()`
never accept or touch `status` from caller input, so a form field can never
smuggle an arbitrary status value. `save()` always creates a fresh row
rather than upserting on a natural key — a same-name, same-day goal
(e.g. two "Holiday" goals) must never silently collapse into one.

`GoalContributionWriter` re-asserts ownership of **both** the goal and the
transaction before it writes, and treats a foreign or missing id as a silent
no-op — the attribution actions are invokable straight from the browser, so
confirming that a row exists would itself leak.

## Pot linking

A goal may link a savings pot (from the Pots module), which then supplies both
its progress figure and its run-rate. Every create/update that touches the
linked pot runs the goal write and the pot link/unlink/relink as **one DB
transaction**, so a failed link (one-pot-per-goal violation, a cross-user or
category-linked pot id) rolls back the goal mutation too — there is never an
orphan goal or a silently-lost previous pot link. That transaction lives in
`Internal/Services/GoalPotLinkWriter`, not in the Livewire component: a
transaction spanning two modules' writers is a rule about the domain, not about
the page that triggers it. The link itself goes through
`PotWriter::linkGoal()`, which touches only `goal_id`; a pot already linked to a
category is refused there with a typed `PotLinkedToCategoryException`, and a pot
that already funds another goal with a typed `PotAlreadyLinkedException`, so the
invariants belong to Pots and the page only names the field and the language.

That second refusal is the one that shipped missing, and the route to it was the
form rather than the picker. `flux:modal dismissible` leaves the server-side
form populated, and "Add goal" used to clear only `editGoalId` — so editing a
goal, dismissing the modal and pressing "Add goal" re-submitted the edited
goal's own name, amount, date and pot as a *second* goal, and the pot moved with
it. Two goals called "Japan trip", the original left at 0% with no pot. Both
halves are guarded: `GoalsPage::startCreate()` empties the form and is what
every "Add goal" trigger calls, the modal's `@close` runs `cancel()` so a
dismissal clears it too, and the pot is refused to the second goal even if a
crafted request reaches the writer with the first goal's pot id.

The linked-pot picker on `/goals` excludes pots already linked to a goal or a
category, except — while editing — the pot currently linked to the goal being
edited, via a single shared base query reused (via `UNION`) by both branches so
they cannot drift apart.

That picker is built in `render()`, so a pot archived on the Pots page after the
modal opened is still one of its options. Choosing it raises
`PotNotFoundException` from `PotWriter::linkGoal()`, and
`GoalsPage::applyWriteFailure()` answers it with
`goals::messages.errors.pot_missing` — the pot is gone, pick another or leave
the goal unlinked. It used to fall through to `errors.generic`, *"That goal
could not be saved. Check the fields and try again."*, printed under a picker
whose value the reader had just chosen from it. Which of "no such pot" and "not
yours" it was is left unsaid for the reason
[the Pots module gives](../pots/move-refusals.md#why-no-such-pot-and-not-yours-stay-one-message).

`GoalAlreadyLinkedException` is the one `linkGoal()` refusal with no branch of
its own, because no route reaches it from this page: `relink()` unlinks the
goal's previous pot inside the same transaction before linking the new one, so
by the time `assertGoalOwnedAndFree()` looks, no active pot holds the goal.

## Assigning a transaction to a goal

Attribution happens on the Ledger transaction detail screen, next to the
category, split and counterparty pickers — the one screen where a transaction
is assigned to anything. `TransactionDetail::attributeToGoal()` and
`removeGoalAttribution()` are thin wrappers over `GoalContributionWriter`.

Attribution is deliberately **not** behind the reconciled lock the sibling
mutators on that screen enforce: it writes a separate row and leaves the
reconciled transaction untouched, and a reconciled row is exactly the confirmed
money a goal wants to count.

Every write raises `Sync\Public\Events\GoalContributionMutated`, which the
Sync capture listener records as a `create_row` or `delete_tombstone` op — an
attribution made on the desktop reaches the phone.
