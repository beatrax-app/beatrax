---
phase: 08-recurring-detection-fixed-payments-view
reviewed: 2026-05-17T12:00:00Z
depth: standard
files_reviewed: 60
files_reviewed_list:
  - Modules/Categorization/Public/Services/MerchantMemoryQuery.php
  - Modules/Categorization/tests/Unit/MerchantMemoryQueryBatchTest.php
  - Modules/Core/Internal/Http/Livewire/SettingsPage.php
  - Modules/Core/Models/User.php
  - Modules/Core/Resources/views/livewire/dashboard.blade.php
  - Modules/Core/Resources/views/livewire/settings-page.blade.php
  - Modules/Core/Resources/views/livewire/top-nav.blade.php
  - Modules/Core/tests/Feature/SettingsRecurringFieldsTest.php
  - Modules/Recurring/Database/Migrations/2026_05_18_010001_create_recurring_series_table.php
  - Modules/Recurring/Database/Migrations/2026_05_18_010002_create_recurring_series_occurrences_table.php
  - Modules/Recurring/Database/Migrations/2026_05_18_010003_create_recurring_series_transitions_table.php
  - Modules/Recurring/Database/Migrations/2026_05_18_010004_add_recurring_settings_to_users.php
  - Modules/Recurring/Internal/CadenceInferrer.php
  - Modules/Recurring/Internal/Detection/ClusterKeyComposer.php
  - Modules/Recurring/Internal/Detectors/ExpenseSeriesDetector.php
  - Modules/Recurring/Internal/Detectors/IncomeSeriesDetector.php
  - Modules/Recurring/Internal/Http/Livewire/FixedPaymentsCard.php
  - Modules/Recurring/Internal/Http/Livewire/RecurringPage.php
  - Modules/Recurring/Internal/Http/Livewire/RecurringReviewPage.php
  - Modules/Recurring/Internal/Http/Livewire/RecurringSeriesDetailPage.php
  - Modules/Recurring/Internal/Jobs/DetectRecurringSeriesJob.php
  - Modules/Recurring/Internal/StateMachines/InvalidStateTransitionException.php
  - Modules/Recurring/Internal/StateMachines/RecurringSeriesStateMachine.php
  - Modules/Recurring/Models/RecurringSeries.php
  - Modules/Recurring/Models/RecurringSeriesOccurrence.php
  - Modules/Recurring/Models/RecurringSeriesTransition.php
  - Modules/Recurring/Providers/RecurringServiceProvider.php
  - Modules/Recurring/Public/Actions/ApproveRecurringSeries.php
  - Modules/Recurring/Public/Actions/EditRecurringSeriesName.php
  - Modules/Recurring/Public/Actions/EditRecurringSeriesVarianceTolerance.php
  - Modules/Recurring/Public/Actions/RejectRecurringSeries.php
  - Modules/Recurring/Public/Actions/SnoozeRecurringSeries.php
  - Modules/Recurring/Public/Actions/UnRejectRecurringSeries.php
  - Modules/Recurring/Public/Contracts/SeriesDetector.php
  - Modules/Recurring/Public/Dto/NextExpectedChargeDto.php
  - Modules/Recurring/Public/Dto/RecurringOccurrenceDto.php
  - Modules/Recurring/Public/Dto/RecurringSeriesAmountTrendDto.php
  - Modules/Recurring/Public/Dto/RecurringSeriesDto.php
  - Modules/Recurring/Public/Events/RecurringSeriesApproved.php
  - Modules/Recurring/Public/Events/RecurringSeriesCadenceFlipped.php
  - Modules/Recurring/Public/Events/RecurringSeriesDetected.php
  - Modules/Recurring/Public/Events/RecurringSeriesRejected.php
  - Modules/Recurring/Public/Services/FixedPaymentsViewQuery.php
  - Modules/Recurring/Public/Services/RecurringSeriesQuery.php
  - Modules/Recurring/Resources/views/livewire/fixed-payments-card.blade.php
  - Modules/Recurring/Resources/views/livewire/recurring-page.blade.php
  - Modules/Recurring/Resources/views/livewire/recurring-review-page.blade.php
  - Modules/Recurring/Resources/views/livewire/recurring-series-detail-page.blade.php
  - Modules/Recurring/Routes/web.php
  - Modules/Recurring/composer.json
  - Modules/Recurring/tests/Feature/CrossUserRecurringSeriesIsolationTest.php
  - Modules/Recurring/tests/Feature/DetectRecurringSeriesJobTest.php
  - Modules/Recurring/tests/Feature/FixedPaymentsViewQueryTest.php
  - Modules/Recurring/tests/Feature/IncomeDetectorTest.php
  - Modules/Recurring/tests/Feature/RecurringMigrationTest.php
  - Modules/Recurring/tests/Feature/RecurringPageReDetectTest.php
  - Modules/Recurring/tests/Feature/RecurringReviewPageBulkActionsTest.php
  - Modules/Recurring/tests/Feature/SnoozeRecurringSeriesTest.php
  - Modules/Recurring/tests/Feature/TopNavBadgeComposerTest.php
  - Modules/Recurring/tests/Unit/CadenceInferenceTest.php
  - Modules/Recurring/tests/Unit/RecurringSeriesStateMachineTest.php
  - bootstrap/providers.php
  - resources/js/app.js
  - resources/views/components/apex-chart-smoke.blade.php
  - routes/console.php
  - tests/Contracts/BoundaryArchTest.php
  - tests/Contracts/RecurringDetectionContractTest.php
findings:
  critical: 4
  warning: 10
  info: 8
  total: 22
status: issues_found
---

# Phase 8: Code Review Report

**Reviewed:** 2026-05-17T12:00:00Z
**Depth:** standard
**Files Reviewed:** 60+
**Status:** issues_found

## Summary

The Phase 8 deliverable — recurring-series detection (expense + income), the
`/recurring` review queue, the `/recurring/series/{id}` drill-in, the dashboard
"Fixed monthly payments" card, the dedicated state machine, and the four new
migrations — is in place with broad test coverage and a clean cross-user
isolation suite. The bounded-module discipline holds: `BoundaryArchTest`
already encodes `noTransactionWritesFromRecurring`,
`noOtherRecurringSeriesStateMutator`, and `noSynchronousDetectionInRequestLifecycle`,
and no external module reaches into `Modules\Recurring\Internal`.

Adversarial review surfaces four BLOCKER-level defects:

1. The detector cadence-flip path on the income side resolves the "existing
   series" lookup by free-form `detected_name` rather than the IBAN-keyed
   cluster id used for primary clustering. For the documented multi-IBAN
   payroll case (covered explicitly by `IncomeDetectorTest::two-employer-salary`)
   a cadence flip on Employer A's series can silently overwrite Employer B's
   row.
2. The `RecurringSeriesQuery::scoped` cursor pagination ignores the primary
   sort key when the caller passes `monthly_equivalent_minor` — `approvedForUser`
   is the public-API entrypoint exposing this. Pagination beyond page 1 returns
   an unrelated id-window slice, not the next-largest monthly-equivalent rows.
3. `SnoozeRecurringSeries` claims atomicity but writes `snoozed_until` and the
   state transition in **two separate transactions**. A failure between them
   leaves the row with a future `snoozed_until` and the original `pending` /
   `approved` state.
4. `recurring-review-page.blade.php` embeds the user-controlled
   `$row->displayName()` directly inside a single-quoted Alpine `x-data`
   expression — Blade's HTML-entity escaping does not protect JavaScript
   string contexts, so any apostrophe in a display name breaks the page and
   a crafted name (in a future multi-user scenario, or an attacker who gains
   any rename surface) can execute arbitrary JS.

Beyond those, the project-rule violations are pervasive:

- The detector / SFC / Blade tree carries **active GSD-planning references**
  (`Plan 05`, `Phase 9 / Phase 10`, `Wave 0`, `D-8xx`, `issue #12 carry-forward`)
  in production PHPDoc/comments and Blade templates. This directly violates
  the codebase-agnostic-from-GSD rule recorded in user memory.
- The Recurring snooze buttons in the review-page Blade call the `now()`
  global helper to compute snooze-until timestamps — these are domain values
  computed on the server, not template helpers, and should travel through the
  injected `Clock`.

## Structural Findings (fallow)

No structural findings substrate was provided to this review. Findings below
are all narrative.

## Narrative Findings (AI reviewer)

## Critical Issues

### CR-01: Income detector cadence-flip lookup uses `detected_name` and silently mixes IBAN-distinct payroll series

**File:** `Modules/Recurring/Internal/Detectors/IncomeSeriesDetector.php:184-191`

**Issue:**
For income, the detector's primary cluster key (and the `cluster_key` written
to the row) is the IBAN when present (line 114). However, when a cadence flip
makes `existingBySameCluster` miss (because the new cluster key now carries a
different cadence token), the secondary lookup falls back to:

```php
$existingByCounterparty = RecurringSeries::query()
    ->where('user_id', $user->id)
    ->where('direction', 'income')
    ->where('detected_name', $counterpartyNormalized)
    ->where('latest_currency', $currency)
    ->first();
```

`detected_name` is stored from `$counterpartyNormalized` regardless of which
IBAN the cluster keyed on. In the multi-IBAN payroll case covered by
`IncomeDetectorTest::two-employer-salary` (two employers sharing a
`detected_name` like `"global employer"` but distinct IBANs), this query
matches **either** series and `->first()` returns the one with the lower
`id`. A cadence flip on Employer B's series therefore refreshes Employer A's
row, possibly demoting A's `approved → cadence_changed` and writing B's new
amount onto A's `latest_amount_minor`. The expense detector does not have
this defect because expense clustering itself keys on
`counterparty_normalized` (no IBAN), so `detected_name` is a true cluster
identifier there.

**Fix:**
Carry the IBAN (or the composed `counterpartyKey`) into the cadence-flip
fallback lookup. One option is to add a normalized `cluster_key_root` column
that omits the cadence token, indexed for this seam. The lighter fix is to
store the IBAN on the series row (e.g. `cluster_counterparty_key`) and key
the fallback lookup on that:

```php
$existingByCounterparty = RecurringSeries::query()
    ->where('user_id', $user->id)
    ->where('direction', 'income')
    ->where('cluster_counterparty_key', $counterpartyKey) // not detected_name
    ->where('latest_currency', $currency)
    ->first();
```

A test reproducing the bug: seed Employer A + Employer B with the same
`detected_name` but distinct IBANs at monthly cadence; flip Employer B's
cadence to quarterly on the second pass; assert that A's row is untouched
and B's row is the one that transitions to `cadence_changed`.

---

### CR-02: `RecurringSeriesQuery::scoped` cursor pagination ignores `monthly_equivalent_minor` sort order

**File:** `Modules/Recurring/Public/Services/RecurringSeriesQuery.php:215-232`

**Issue:**
`scoped()` accepts a `$primarySort` parameter and orders by
`monthly_equivalent_minor DESC, id DESC` when the caller asks for it. But the
cursor predicate is hardcoded to `id`:

```php
if ($cursorId !== null) {
    $query->where('id', '<', $cursorId);
}
```

For the id-sorted callers (`pendingForUser`, `rejectedForUser`,
`cadenceChangedForUser`) the cursor works. For `approvedForUser`, which is
the Public-API entrypoint that orders by `monthly_equivalent_minor DESC`,
the cursor returns a **disjoint** id-window of approved series — not the
next page of largest monthly-equivalents. Page 2 will skip large-equivalent
rows whose ids happen to exceed the cursor and surface small-equivalent rows
whose ids fall below it.

This is currently latent because `approvedForUser` is not called from any
HTTP surface in this phase (only from tests). The class-level docblock
explicitly promises monthly-equivalent ordering, so any future paged caller
will inherit the bug silently.

**Fix:**
Pair the cursor with a composite tuple matching the order-by, or accept the
cursor as `(monthlyEquivalent, id)`:

```php
if ($cursorId !== null) {
    if ($primarySort === 'monthly_equivalent_minor') {
        // Caller must pass both halves of the composite cursor.
        $row = $this->db->connection()->table('recurring_series')
            ->where('id', $cursorId)->first(['monthly_equivalent_minor']);
        if ($row !== null) {
            $cursorEq = self::toInt($row->monthly_equivalent_minor);
            $query->where(function ($q) use ($cursorEq, $cursorId): void {
                $q->where('monthly_equivalent_minor', '<', $cursorEq)
                  ->orWhere(function ($q2) use ($cursorEq, $cursorId): void {
                      $q2->where('monthly_equivalent_minor', $cursorEq)
                         ->where('id', '<', $cursorId);
                  });
            });
        }
    } else {
        $query->where('id', '<', $cursorId);
    }
}
```

Or simpler: remove the public `approvedForUser` cursor support altogether
until a real caller arrives, and document that the projection is "top-N only".

---

### CR-03: `SnoozeRecurringSeries` is not atomic — `snoozed_until` and `state` cross transaction boundaries

**File:** `Modules/Recurring/Public/Actions/SnoozeRecurringSeries.php:57-75`

**Issue:**
The class-level docblock and `SnoozeRecurringSeriesTest::writes snoozed_until
and flips state to snoozed atomically` both claim the snooze write is atomic
with the state transition. The implementation opens a transaction to update
`snoozed_until`, **commits that transaction**, then reloads the row and calls
`stateMachine->transition()` which opens its **own separate transaction** to
flip the state and append the audit row:

```php
$this->db->connection()->transaction(function () use ($seriesId, $untilString): void {
    $this->db->connection()->table('recurring_series')
        ->where('id', $seriesId)
        ->update(['snoozed_until' => $untilString]);
});

/** @var RecurringSeries $fresh */
$fresh = RecurringSeries::query()->findOrFail($seriesId);

$this->stateMachine->transition($fresh, 'snoozed', 'user_action', 'user', ...);
```

If a connection failure, deadlock, or PHP fatal happens between the two
transactions (or a concurrent sweep job mutates the row in between), the
row ends up with a future `snoozed_until` and its original `pending` /
`approved` / `cadence_changed` state. The UI then displays a series
labelled "active" but with a snooze date in the future; the next sweep
won't expire-snooze it (state isn't `snoozed`) so the row will surface
stale until the user manually snoozes again.

Additionally, the surrounding transaction is unnecessary — the inner block
is a single `UPDATE` and `$this->db->connection()` is invoked twice but
returns the same instance each call (so the inner write rides the same
connection); the wrap adds no concurrency guarantee but does add wall time.

**Fix:**
Move the `snoozed_until` UPDATE inside the state-machine transition's
transaction, or refactor `RecurringSeriesStateMachine::transition` to accept
an optional column patch:

```php
public function transition(
    RecurringSeries $series,
    string $toState,
    string $reason,
    string $actor,
    ?string $notes = null,
    array $extraColumns = [], // new
): void {
    ...
    $connection->table('recurring_series')
        ->where('id', $seriesId)
        ->update(array_merge($extraColumns, [
            'state' => $toState,
            'updated_at' => $now,
        ]));
    ...
}
```

Then in `SnoozeRecurringSeries`:

```php
$this->stateMachine->transition(
    $series, 'snoozed', 'user_action', 'user',
    'snoozed_until='.$untilString,
    ['snoozed_until' => $untilString],
);
```

This collapses to one transaction, one row lock, one audit row, and the
two columns move together or not at all.

---

### CR-04: User-controlled `displayName()` interpolated into JS string in `recurring-review-page.blade.php`

**File:** `Modules/Recurring/Resources/views/livewire/recurring-review-page.blade.php:162`

**Issue:**

```blade
<div x-data="{ editing: false, newName: '{{ $row->displayName() }}' }" ...>
```

Blade's `{{ }}` escapes HTML entities — apostrophes become `&#039;`. When the
browser parses the attribute value, those entities decode back to `'`,
producing the literal Alpine source `{ editing: false, newName: 'Alice's
Subscriptions' }`. Alpine.js then evaluates this as JavaScript and the
embedded apostrophe terminates the string, producing a JS syntax error that
breaks the entire SFC interaction (the dropdown / edit affordance silently
stops working).

In v1 the rename is only reachable by the row's owner (self-XSS), but
project requirements explicitly call out **multi-user readiness from v1**.
Once a second user lands, any code path that surfaces another user's name
(audit views, listener narrations) becomes an XSS sink. A crafted name like
`'; fetch('/logout'); //` would execute on first render.

**Fix:**
Use Laravel's `@js()` directive (preferred for Alpine values), which JSON-
encodes the value and is JS-safe:

```blade
<div x-data="{ editing: false, newName: @js($row->displayName()) }" ...>
```

`@js` emits e.g. `"Alice's Subscriptions"` (proper JS string with escaped
quotes if needed). Apply the same fix anywhere user-controlled values are
embedded in `x-data` / `wire:click` JS contexts.

---

## Warnings

### WR-01: Project-rule violation — `now()` global helper used in `recurring-review-page.blade.php` snooze buttons

**File:** `Modules/Recurring/Resources/views/livewire/recurring-review-page.blade.php:144,150,156`

**Issue:**
The three snooze buttons compute their target ISO timestamps via the `now()`
global helper:

```blade
wire:click="snooze({{ $row->seriesId }}, '{{ now()->addWeek()->toIso8601String() }}')"
```

`now()` is a Laravel global helper that resolves a `Carbon::now()` instance.
The project memory rule (`feedback_laravel_di_only.md`) is unambiguous: "no
facade calls / global helpers" in module code. Blade is technically rendered
inside the module, and the value being computed is a domain timestamp (a
snooze-until target), not a template-only helper like `route()` or `asset()`.
The injected `Clock` contract exists precisely to make detector / action
timing deterministic in tests; embedding `now()` here circumvents
`CarbonImmutable::setTestNow()` only because Carbon's static now-mock catches
it — but it is the wrong direction architecturally.

**Fix:**
Compute the three snooze targets on the `RecurringReviewPage` component via
injected `Clock` and pass them to the view:

```php
public function render(CurrentUser $currentUser, RecurringSeriesQuery $query, ViewFactory $views, Clock $clock): View
{
    ...
    $snoozeTargets = [
        '1w' => $clock->now()->addWeek()->toIso8601String(),
        '1m' => $clock->now()->addMonth()->toIso8601String(),
        '3m' => $clock->now()->addMonths(3)->toIso8601String(),
    ];
    return $views->make('recurring::livewire.recurring-review-page', [
        'rows' => $rows, 'tab' => $this->tab, 'snoozeTargets' => $snoozeTargets,
    ]);
}
```

```blade
wire:click="snooze({{ $row->seriesId }}, '{{ $snoozeTargets['1w'] }}')"
```

### WR-02: Project-rule violation — GSD planning references leak into production PHPDoc and Blade

**File:** Multiple — sample below

- `Modules/Recurring/Providers/RecurringServiceProvider.php:46`: `(NEVER the view() global helper — issue #12 carry-forward).`
- `Modules/Recurring/Providers/RecurringServiceProvider.php:131`: `Placeholder for Phase 9 / Phase 10 ...`
- `Modules/Recurring/Internal/Jobs/DetectRecurringSeriesJob.php:47`: `... lands in Plan 05) ...`
- `Modules/Recurring/Internal/Http/Livewire/RecurringPage.php:27,33,39`: `false by default per D-852`, `Bulk actions land in Plan 05`
- `Modules/Recurring/Internal/Http/Livewire/RecurringReviewPage.php:43`: `full wiring lands in Plan 05`
- `Modules/Recurring/Internal/CadenceInferrer.php:100`: `Rolling-window guard (D-844 MAX_MISSED_PER_WINDOW)`
- `Modules/Recurring/Internal/Detectors/IncomeSeriesDetector.php:110`: `IBAN-primary cluster key with normalized-description fallback (D-817).`
- `Modules/Recurring/Public/Actions/SnoozeRecurringSeries.php:23`: `Phase 9 / Phase 10 listeners narrate ...`
- `Modules/Recurring/Resources/views/livewire/recurring-page.blade.php:3,6,8,12`: `per D-818 / D-819 / D-852`, `per D-840`, `per D-826`, `D-830`

**Issue:**
The user memory rule `feedback_codebase_gsd_agnostic.md` is explicit: "No
`.planning/` / PLAN.md / RESEARCH.md references in code, PHPDocs, or
comments." Plan-ordinals (`Plan 05`), wave-ordinals (`Wave 0`), phase-
ordinals (`Phase 9 / Phase 10`), decision-codes (`D-817`, `D-844`, etc.),
and GitHub issue references (`issue #12`) all reach into the GSD planning
substrate. They will rot the moment plan numbers are reshuffled and they
violate the documented constraint.

**Fix:**
Rewrite each docblock / Blade comment to describe what the code does now,
without referring to the planning vehicle that introduced it. For example
`(NEVER the view() global helper — issue #12 carry-forward)` becomes
`(uses the View Factory contract; the global view() helper is forbidden by
project convention)`. `false by default per D-852` becomes
`false by default — transfers panel is opt-in disclosure`.

### WR-03: `RecurringPage::reDetect` dispatches without a guard against an unauthenticated request

**File:** `Modules/Recurring/Internal/Http/Livewire/RecurringPage.php:59-63`

**Issue:**
`reDetect()` calls `$currentUser->user()->id` directly. If the auth middleware
is misconfigured (e.g., a future test forgets `->actingAs(...)`,
`CurrentUser::user()` returns `null` per the typical contract shape), this
will throw a less-than-friendly null-method error and may already have
called `dispatch('toast', ...)` partially. The route is currently auth-
gated so this is defensive but cheap.

**Fix:**
Add an `if (! $currentUser->isAuthenticated()) { return; }` early-return at
the head of `reDetect()` mirroring the composer's check.

### WR-04: `RecurringPage::render()` re-runs every query on every Livewire round-trip even when only `$transfersExpanded` flipped

**File:** `Modules/Recurring/Internal/Http/Livewire/RecurringPage.php:65-84`

**Issue:**
`render()` always calls `$query->viewForUser($user)` (which is a 3-query
batch) plus `$query->monthlyEquivalentTotals($user)` regardless of which
action fired. The `toggleTransfers()` action only flips a bool, but its
re-render re-executes the full read pipeline. For a single user with ~10
approved series this is negligible; for the documented "first big
backfill ~20 candidates" pitfall it amounts to four extra SQL queries per
disclosure toggle.

Since this review's scope excludes performance per `<review_scope>`, this is
flagged for hygiene rather than correctness — the queries are read-mostly
and stay user-scoped. Worth noting because a Livewire `#[Computed]` cache
would erase the cost.

**Fix:**
Wrap the two service calls in `#[Computed]` properties so Livewire memoises
them inside a single render cycle, and consider mounting `$totals` once at
component boot rather than per render.

### WR-05: `$series->state === 'rejected'` check in `IncomeSeriesDetector::processCluster` happens AFTER the cadence-fallback resolution but BEFORE the metrics refresh

**File:** `Modules/Recurring/Internal/Detectors/IncomeSeriesDetector.php:198-217`

**Issue:**
The flow is:
1. Fetch `existingBySameCluster` (cluster_key match).
2. Fetch `existingByCounterparty` (detected_name + currency match).
3. `$existing = $existingBySameCluster ?? $existingByCounterparty;`
4. If `$existing === null` insert new series; return.
5. If `$existing->state === 'rejected'` return.
6. Refresh metrics on `$existing`.

In the cadence-flip case where `existingBySameCluster` is null but
`existingByCounterparty` resolves to a rejected series, the early-return at
step 5 means the new cadence is **silently dropped** — a freshly clustering
quarterly Spotify pattern never spawns a new pending series because a
previously-rejected monthly Spotify series matches by name+currency.

The expense detector has the same shape (line 193). Whether this is the
intended semantic ("once rejected, all cadence variants for the same
counterparty stay rejected") depends on product intent; if so the docblock
should state that explicitly. If not — and the user really did mean "this
specific cadence was wrong, but a different cadence might be right" — this
is a defect.

**Fix:**
Confirm intent and add either:
- a docblock note: "rejection covers the entire counterparty (any cadence)",
  plus a test asserting a rejected monthly series suppresses a new quarterly
  one for the same counterparty; OR
- restructure so that a cadence-band miss on `existingBySameCluster` falls
  through to "insert new series" rather than reusing a rejected
  same-counterparty row.

### WR-06: `RecurringSeriesQuery::amountTrendForSeries` falls back to `currency = 'EUR'` silently when the series row has an empty `latest_currency`

**File:** `Modules/Recurring/Public/Services/RecurringSeriesQuery.php:159-163`

**Issue:**

```php
$currency = self::toString($seriesRow->latest_currency);
if ($currency === '') {
    $currency = 'EUR';
}
```

A schema invariant says `latest_currency` is non-null (`->string('latest_currency', 3)`),
and the detector writes it on every insert path. But the silent fallback hides
a class of bugs (corrupt row, unexpected null cast) by labelling the chart
axis as EUR when the underlying observations carry a different currency.
ApexCharts will then render the native-amount data with an EUR label —
misleading.

**Fix:**
Either fail loudly (`throw new RuntimeException`) or drop the chart entirely
(`return new RecurringSeriesAmountTrendDto(... currency: '', points: [])`)
and surface a "no chart available" message at the Blade layer.

### WR-07: `FixedPaymentsCard` filters AFTER `topByMonthlyEquivalent`'s limit, so "This month only" can show fewer than 6 even when more are due

**File:** `Modules/Recurring/Internal/Http/Livewire/FixedPaymentsCard.php:50-66`

**Issue:**
The card fetches the top 6 series by monthly equivalent, then filters in
PHP to "this month only". A user with 30 approved series, 10 of which are
due this month, will see only the subset of the top-6-by-magnitude that
happen to fall in May, possibly 0–6 rows. The Blade then renders "No
approved recurring series yet." (the empty-state message), even though
10 are actually due this month.

**Fix:**
Push the date filter into the query layer:

```php
public function topByMonthlyEquivalent(User $user, int $limit = 6, ?CarbonInterface $monthStart = null, ?CarbonInterface $monthEnd = null): array
```

And refine the empty-state message to distinguish "no series at all" from
"none due this month".

### WR-08: `pendingForUser` queries `state IN ('pending', 'cadence_changed')` but the docblock + the public method name only mention "pending"

**File:** `Modules/Recurring/Public/Services/RecurringSeriesQuery.php:39-50`

**Issue:**
`pendingForUser()` returns rows whose state is either `pending` OR
`cadence_changed`. The top-nav badge composer uses `pendingCountForUser`
which has the same widened set. The class-level docblock says cursor
pagination matches the chains-side review queue but does not call out the
inclusion of `cadence_changed`. The
`RecurringReviewPage` then separately uses
`cadenceChangedForUser($user)` for its own tab. Result: a `cadence_changed`
row appears in both the "Pending" tab AND the "Cadence changed" tab,
double-counted from the user's perspective.

**Fix:**
Either rename `pendingForUser` → `pendingOrCadenceChangedForUser` (and the
badge accordingly) and call this out in the docblock, or strip
`cadence_changed` out of `pendingForUser`/`pendingCountForUser` and rely on
the dedicated `cadence_changed` tab + badge if separate signalling is
needed.

### WR-09: Income detector refreshes metrics on `snoozed` series — wakes the row's amounts up while the user explicitly told it to wait

**File:** `Modules/Recurring/Internal/Detectors/IncomeSeriesDetector.php:215-230` (and the expense mirror at `ExpenseSeriesDetector.php:193-210`)

**Issue:**
The "rejected → skip" branch correctly preserves the user's veto, but
`snoozed` rows fall through to `refreshExistingSeries`, which overwrites
`latest_amount_minor`, `monthly_equivalent_minor`, `next_expected_at`, and
`cluster_key`. The snooze affordance is meant to be a "hide for now"
shortcut for the user — refreshing the underlying metrics in the background
defeats the calm intent (the row will pop back into the review queue
showing a different number than what the user paused on).

**Fix:**
Either short-circuit `snoozed` rows the same way `rejected` ones are
skipped, or document the intentional refresh ("snooze hides from review;
the underlying detector still maintains current numbers so the un-snoozed
row reflects today's reality").

### WR-10: `EditRecurringSeriesName` does not enforce a length cap on the override

**File:** `Modules/Recurring/Public/Actions/EditRecurringSeriesName.php:33-53`

**Issue:**
`display_name_override` is a default Laravel `string` column (varchar(255)).
The action accepts any string and writes it via raw query builder without
length validation. A user pasting 300 chars triggers a SQL error
("Data too long for column"), surfaced as an uncaught exception → 500
response. Trim whitespace is also not applied — a name like `"   "` would
re-render as `null` only because the SFC's `editName` handler trims it
before calling the action, but a direct cross-module / programmatic call to
the action bypasses that.

**Fix:**
Validate inside the action:

```php
public function __invoke(int $seriesId, User $user, ?string $displayNameOverride): void
{
    if ($displayNameOverride !== null) {
        $displayNameOverride = trim($displayNameOverride);
        if ($displayNameOverride === '') {
            $displayNameOverride = null;
        } elseif (mb_strlen($displayNameOverride) > 120) {
            throw new InvalidArgumentException('Display name must be 120 characters or fewer.');
        }
    }
    ...
}
```

---

## Info

### IN-01: `RecurringServiceProvider::registerDashboardCardComposer()` is an empty placeholder

**File:** `Modules/Recurring/Providers/RecurringServiceProvider.php:135-138`

**Issue:**
The method is intentionally empty per the comment. Dead code that exists
"so a later plan can attach data" is noise — it widens the provider's
public surface for a hypothetical use that may or may not arrive. Either
drop the method or leave only the comment block on the calling line.

**Fix:**
Delete the method and remove the call site (line 98). Add the wiring when
a real composer is needed.

### IN-02: Trailing closing `<?php`-less PHP files mix tabs/spacing in test fixtures

**File:** `Modules/Recurring/tests/Feature/IncomeDetectorTest.php` and peers

**Issue:**
Multiple feature tests rely on free-form helper functions (`fpvUser`,
`rsdSeries`, `rrbSeries`, …) defined at the top of each test file. The
naming uses 4–5-letter prefixes that change per file (`fpv`, `rsd`, `rrb`,
`drsj`, `idt`, `arc`, etc.). Pest's auto-loading puts every helper in the
global function namespace, which can cause cross-file collisions if a
future helper shares a prefix.

**Fix:**
Move shared helpers into a `Modules/Recurring/tests/Helpers/` directory
imported via `use` / `require_once`, or into the per-module `Pest.php`.
Cosmetic, not blocking.

### IN-03: `monthlyEquivalent` rounding loses sub-cent precision on weekly cadences

**File:** `Modules/Recurring/Internal/Detectors/ExpenseSeriesDetector.php:411-420` (and income mirror)

**Issue:**

```php
'weekly' => (int) round($latestAmountMinor * 4.33),
```

The 4.33 multiplier is the approximate weeks-per-month (52/12 ≈ 4.333).
For a €10.00/week subscription (1000 minor), the projected monthly is
`round(1000 * 4.33) = 4330` minor → €43.30/mo, while the true 52/12 value
is €43.33. Drift is small but real, and the test
`FixedPaymentsViewQueryTest::applies the per-cadence monthly multiplier`
pins the rounded behaviour. Acceptable for the dashboard but worth
documenting in the docblock so a future reader doesn't think a fix is due.

**Fix:**
Use `52/12` explicitly:

```php
'weekly' => (int) round($latestAmountMinor * 52 / 12),
```

### IN-04: `CadenceInferrer::infer` uses `$previous->diffInDays($timestamp)` with `abs()` defensively, but the input contract is "sorted ascending"

**File:** `Modules/Recurring/Internal/CadenceInferrer.php:74-81`

**Issue:**
The PHPDoc says `@param list<CarbonImmutable> $sortedTimestamps ascending`,
so `$previous->diffInDays($timestamp)` should always be ≥ 0 — the `abs()`
either documents distrust of the caller or papers over a future bug where
the caller sorts wrong. Pick one: assert the invariant (`assert(...)`) or
drop the `abs()`.

**Fix:**
Remove `abs()` and trust the contract, OR add a precondition assertion at
the start of `infer()`.

### IN-05: `RecurringSeriesQuery::amountTrendForSeries` collection-`reverse` is correct but obscure

**File:** `Modules/Recurring/Public/Services/RecurringSeriesQuery.php:181`

**Issue:**

```php
$ordered = $rows->reverse()->values();
```

The DB query orders DESC and limits to N for "newest N" semantics; the
reverse then re-orders ASC for the chart. A reader has to follow the two
opposite orderings carefully. Replacing with a subquery or a CTE that
selects the top-N then reorders inside SQL would be clearer (and only
slightly slower). Cosmetic.

**Fix:**
Add a one-line comment: `// reverse to ASC for left-to-right chart axis after DESC-limit fetch.`

### IN-06: `RecurringSeriesStateMachine::toIntOrNull` returns `null` for a stringly-numeric `'0'` user_id

**File:** `Modules/Recurring/Internal/StateMachines/RecurringSeriesStateMachine.php:140-147`

**Issue:**

```php
private static function toIntOrNull(mixed $value): ?int
{
    if ($value === null) {
        return null;
    }
    return is_numeric($value) ? (int) $value : null;
}
```

A `user_id` of `'0'` is technically numeric and gets cast to `0`, then
inserted into `recurring_series_transitions.user_id`. The `users` table
likely has no id 0 (Laravel auto-increment starts at 1), so this is a
theoretical edge. Worth ruling out by tightening: `return is_numeric($value) && (int) $value > 0 ? (int) $value : null;`.

**Fix:**
Tighten the predicate to `> 0`.

### IN-07: `DetectRecurringSeriesJob` does not log when `expireSnoozes` skips an `id === 0` row

**File:** `Modules/Recurring/Internal/Jobs/DetectRecurringSeriesJob.php:114-122`

**Issue:**

```php
foreach ($rows as $row) {
    $id = is_numeric($row->id) ? (int) $row->id : 0;
    if ($id === 0) {
        continue;
    }
    ...
}
```

This guard silently swallows rows whose `id` came back as zero / non-numeric.
That's never expected for a valid SQLite primary key, but the silent skip
turns a schema corruption into a no-op rather than a loud failure.

**Fix:**
Either drop the defensive cast entirely (trust the schema) or surface the
unexpected case via the Laravel log channel:

```php
if ($id === 0) {
    Log::warning('DetectRecurringSeriesJob: encountered non-numeric recurring_series.id; skipping.', ['row' => (array) $row]);
    continue;
}
```

(The `Log` facade is already an exemption seam in module code per the
existing carve-out pattern.)

### IN-08: `RecurringSeriesQuery::toDto` re-implements logic that is also in `FixedPaymentsViewQuery::toDto`

**File:** `Modules/Recurring/Public/Services/RecurringSeriesQuery.php:242-291` mirrors `Modules/Recurring/Public/Services/FixedPaymentsViewQuery.php:254-317`

**Issue:**
Two nearly identical `toDto` methods (one inside each Public query class)
duplicate the `latestAmount` / `eurEquivalent` / `monthlyEquivalent` / next-
expected-at / snoozed-until / chain-link decoding. Bugs found in one path
(e.g. the eur-equivalent currency mis-label when `latest_currency` is the
empty string) must be fixed twice. The chain-link resolution differs
intentionally (FixedPaymentsView has the fallback walk), but the rest is
copy-paste.

**Fix:**
Extract a `RecurringSeriesDtoMapper` (or a private trait) so both query
classes call the same hydrator and the fallback chain-link logic stays at
the FixedPaymentsView callsite.

---

_Reviewed: 2026-05-17T12:00:00Z_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
