---
phase: 11-operational-hardening
plan: 04
subsystem: infra
tags: [livewire, blade, tailwind, sqlite, failed-jobs, pest, larastan, arch-test, horizon]

# Dependency graph
requires:
  - phase: 11-operational-hardening
    provides: SystemAlert Eloquent model + SystemAlertQuery::active() + AcknowledgeSystemAlert action from plan 11-01; failed_jobs table from Phase 5 substrate
  - phase: 01-foundation
    provides: Modules\Core\Public\Contracts\Clock, Modules\Core\Public\Contracts\CurrentUser, Tailwind 4 + Livewire 4 starter substrate
provides:
  - DurationParser pure-logic value object (string `30d|12h|2w` → CarbonImmutable offset; `m` token explicitly rejected)
  - diederik:failed-jobs prune --older-than=<token> [--dry-run] artisan command
  - SystemAlertsBanner Livewire 4 SFC (Modules\Core\Internal\Http\Livewire) + Blade view with three explicit severity branches (PurgeCSS-safe by construction)
  - core.system-alerts-banner Livewire component registration + app.blade.php layout slot
  - core::livewire.partials.system-alert-message Blade partial (kind-keyed message templates per UI-SPEC §Severity x Kind Copywriting Contract)
  - BoundaryArchTest::noFacadeCallsFromCoreConsoleCommands invariant (locks DI-only contract for Core console commands)
  - HorizonForceFlagTest invariant (locks A2 assumption — no Horizon supervisor uses force: true)
affects: [12+ future phases — banner is the canonical operational-failure surface; arch invariants lock future contributors]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Three explicit Blade severity branches with literal Tailwind class strings — Tailwind content scanner sees each `border-rose-500` / `border-amber-300` / `border-slate-200` (plus matching bg-/text- tokens) as direct source occurrences; no dynamic class interpolation, no PurgeCSS safelist comments"
    - "Method-parameter DI on Livewire 4 Component subclasses for both render() and acknowledge() — constructor DI banned by phpstan-strict-rules on Component subclasses"
    - "Cross-user safety on the action layer (not the Livewire component): AcknowledgeSystemAlert raises NotFoundHttpException; tests exercise the action directly because Livewire swallows Symfony HTTP exceptions during synthetic ->call() invocations"
    - "Subcommand-style artisan signature with default action: `diederik:failed-jobs {action=prune} {--older-than=30d} {--dry-run}` keeps future verbs (view, retry-all, clear) reachable without renaming the surface"
    - "Append-grep arch invariant for Core console commands: RecursiveIteratorIterator + comment-strip preg_replace + namespace grep + $hits[] + expect()->toBe([], …) — same shape as the Phase 9/10 invariants"

key-files:
  created:
    - Modules/Core/Internal/Console/Support/DurationParser.php
    - Modules/Core/Internal/Console/FailedJobsCommand.php
    - Modules/Core/Internal/Http/Livewire/SystemAlertsBanner.php
    - Modules/Core/Resources/views/livewire/system-alerts-banner.blade.php
    - Modules/Core/Resources/views/livewire/partials/system-alert-message.blade.php
    - Modules/Core/tests/Unit/DurationParserTest.php
    - Modules/Core/tests/Feature/FailedJobsCommandTest.php
    - Modules/Core/tests/Feature/SystemAlertsBannerTest.php
    - tests/Contracts/HorizonForceFlagTest.php
  modified:
    - Modules/Core/Providers/CoreServiceProvider.php
    - resources/views/layouts/app.blade.php
    - tests/Contracts/BoundaryArchTest.php

key-decisions:
  - "DurationParser explicitly rejects the `m` token. Across SI-style short durations `m` is ambiguous between minutes and months; the narrower grammar means every consumer of the parser knows exactly what they got. Callers needing sub-day cutoffs pass `1h` / `12h`; callers needing month-scale cutoffs pass `30d` / `4w`."
  - "match expression in DurationParser carries a default arm that re-throws InvalidArgumentException despite the regex character class already constraining `$unit` to `d|h|w`. PHPStan's match-exhaustiveness check needs the default arm; falling through into the canonical error message keeps the rejection path uniform."
  - "Cross-user 404 invariant for the banner lives on the action layer (AcknowledgeSystemAlert), not on the Livewire component. The component test exercises the action directly for the cross-user case; Livewire catches Symfony HTTP exceptions during synthetic ->call() invocations and reports them as Livewire status codes — testing the action directly captures the architectural guarantee at its real boundary (validated by the existing Phase 5/7/9 cross-user tests)."
  - "BlogEscape-safe Blade comment phrasing: the original blade view comments contained literal `{!! !!}` strings that tripped the acceptance criterion `grep -c '{!! ' = 0`. Comments rephrased to 'unescaped Blade output is forbidden' so the grep continues to pass once the test suite ships and future contributors run the same audit."
  - "Pint normalised `new DurationParser()` → `new DurationParser` (no parentheses on empty constructor) and fully-qualified the InvalidArgumentException reference in the test. Both fixes are stylistic; the test logic is unchanged."

patterns-established:
  - "Three explicit Blade severity branches with literal Tailwind class strings (locked by Phase 11 UI-SPEC §Color tokens). Any future severity-tinted banner / chip / row should mirror this — Tailwind's content scanner is the gate that picks each class up, so dynamic interpolation is forbidden."
  - "Subcommand-style artisan signature with a default action (`{action=prune}`) so bare `php artisan diederik:failed-jobs` runs the prune path. Future verbs can extend without renaming the command. Unknown actions exit non-zero with `Unknown action: <name>` + the supported-verbs list."
  - "Append-grep arch invariant pattern for narrow directory invariants: `is_dir()` early-pass + RecursiveIteratorIterator + comment-strip preg_replace + namespace-import grep + $hits[] aggregation + final `expect($hits)->toBe([], 'Offenders: …')`. Replicated verbatim from Phase 9/10 conventions."
  - "External-config arch invariant pattern: read once, strip comments, assert no regex match. HorizonForceFlagTest demonstrates the shape for any future 'this config file must not contain pattern X' invariant."

requirements-completed: [FND-05]

# Metrics
duration: ~70min
completed: 2026-05-19
---

# Phase 11 Plan 04: Failed-Jobs CLI + SystemAlertsBanner + Arch Invariants Summary

**The Phase 11 vertical-UX closing slice: a `php artisan diederik:failed-jobs prune --older-than=30d [--dry-run]` maintenance CLI, the user-visible `SystemAlertsBanner` Livewire SFC that surfaces every un-acknowledged `system_alerts` row across every authenticated page, and the two arch invariants that lock the operational posture — `noFacadeCallsFromCoreConsoleCommands` and `HorizonForceFlagTest`. After this plan the user can SEE and DISMISS alerts in the browser, the `failed_jobs` table is maintainable from CLI, and the test substrate forbids regressions on both DI-only and Horizon-force-flag invariants.**

## Performance

- **Duration:** ~70 minutes
- **Started:** 2026-05-19 (Wave 3 of Phase 11, after 11-01 / 11-02 / 11-03 merged)
- **Completed:** 2026-05-19
- **Tasks:** 3 (all autonomous, all TDD: RED → GREEN per task)
- **Files created:** 9
- **Files modified:** 3

## Accomplishments

- Shipped **`DurationParser`** as a pure-logic instance-method value object at `Modules/Core/Internal/Console/Support/DurationParser.php`. Single method `subFromNow(string, CarbonImmutable): CarbonImmutable` parses the regex `^(\d+)([dhw])$` and returns `$now->subDays|subHours|subWeeks($amount)`. The `m` token is explicitly rejected as ambiguous between minutes and months per RESEARCH Open Question #2; 16 Pest dataset scenarios lock the contract across 6 valid tokens (including upper-case variants) and 10 invalid inputs (the `m`/`s` tokens, non-numeric, empty, decimal, negative, leading/trailing whitespace, missing-unit, extra-unit).

- Shipped **`FailedJobsCommand`** at `Modules/Core/Internal/Console/FailedJobsCommand.php` registering signature `diederik:failed-jobs {action=prune} {--older-than=30d} {--dry-run}`. Constructor-DI'd with `DatabaseManager`, `Clock`, and `DurationParser` — zero Laravel facade imports. The prune path computes the cutoff via the duration parser, queries `failed_jobs` via the raw query builder against `failed_at < cutoff`, and either prints up to 50 candidate rows with a footer count (dry-run) or runs the DELETE and reports `Removed N rows; M remaining`. Unknown actions exit `FAILURE` with `Unknown action: <name>. Supported: prune.`; invalid duration tokens propagate the parser's grammar message to the operator.

- Shipped **`SystemAlertsBanner`** Livewire 4 Component at `Modules/Core/Internal/Http/Livewire/SystemAlertsBanner.php`. Verbatim shape mirror of `DashboardDriftBadge.php`: method-parameter DI only (constructor DI banned on Livewire components by phpstan-strict-rules), `render(CurrentUser, SystemAlertQuery, ViewFactory)` reads `SystemAlertQuery::active($currentUser->user())` and returns the Blade view, `acknowledge(int, AcknowledgeSystemAlert, CurrentUser)` invokes the action and lets Livewire's automatic re-render drop the dismissed row from the next view.

- Shipped the **Blade SFC view** at `Modules/Core/Resources/views/livewire/system-alerts-banner.blade.php` with three explicit severity branches selected by `@switch ($alert->severity)`. Each branch contains a literal Tailwind class string — `border-rose-500 bg-rose-50 text-rose-900` for critical, `border-amber-300 bg-amber-50 text-amber-900` for warning, `border-slate-200 bg-slate-50 text-slate-700` for info. No dynamic class interpolation; no PurgeCSS safelist comments. Per-tier dismiss button: rose-600 for critical (`focus-visible:ring-rose-600`), slate-100 for warning + info (`focus-visible:ring-slate-900`). Wrapper carries `role="region" aria-label="System alerts"`, critical rows carry `role="alert"`, warning + info rows carry `role="status"` per UI-SPEC §Accessibility. Per UI-SPEC §State Matrix the calm state renders only the empty wrapper — no "no active alerts" placeholder, no visible chrome.

- Shipped the **kind → message-template partial** at `Modules/Core/Resources/views/livewire/partials/system-alert-message.blade.php`. The partial is included by all three severity branches; a `@switch ($alert->kind)` selects one of four locked templates (`backup_corrupt`, `backup_overdue`, `wal_mode_missing`, `synchronous_misconfigured`) per UI-SPEC §Severity x Kind Copywriting Contract. Unknown kinds fall through to the row's own `$alert->message` so future modules can introduce new kinds without an immediate Blade change. Every interpolation runs through Blade default escaping; unescaped output is structurally forbidden — `grep -c '{!! ' …` returns 0 across both the banner view and the partial.

- Registered **`core.system-alerts-banner`** in `CoreServiceProvider::boot()` next to the other core.* Livewire components, and added one new line `@livewire('core.system-alerts-banner')` inside the existing `@auth` block of `resources/views/layouts/app.blade.php` immediately after `core.top-nav` and before `categorization.rule-form-modal` — the verbatim insertion-point diff from UI-SPEC §Banner Insertion Point.

- Extended **`tests/Contracts/BoundaryArchTest.php`** with `noFacadeCallsFromCoreConsoleCommands`. The block walks `Modules/Core/Internal/Console/` recursively, strips block + line comments, and adds any file referencing `Illuminate\Support\Facades\` (after comment strip) to a `$hits[]` list; final `expect($hits)->toBe([], ...)`. The invariant passes against the current substrate (every Core console command — InstallCommand, DoctorCommand, BackupDatabaseCommand, RestoreDatabaseCommand, FailedJobsCommand — was written facade-clean per the plan series).

- Shipped **`tests/Contracts/HorizonForceFlagTest.php`** as a standalone Pest file. The `it(...)` block reads `config/horizon.php`, strips comments, and asserts no `'force' => true` regex match. Locks the A2 assumption that `php artisan down` actually halts Horizon workers — the load-bearing safety property db:restore depends on for the post-purge swap window.

- Manual smoke verified: `php artisan list` lists `diederik:failed-jobs` next to `db:backup`, `db:restore`, `diederik:doctor`, and `diederik:install`; the registered signature line reads `Maintenance operations on the Laravel-managed failed_jobs table.`

## Task Commits

Each task was committed atomically (TDD: test → feat per task):

1. **Task 1 — RED:** `b8d4c7f` (test(11-04): add failing tests for DurationParser + diederik:failed-jobs prune)
2. **Task 1 — GREEN:** `73c81cd` (feat(11-04): ship DurationParser + diederik:failed-jobs prune command)
3. **Task 2 — RED:** `aab64fe` (test(11-04): add failing tests for SystemAlertsBanner Livewire SFC)
4. **Task 2 — GREEN:** `ec2f439` (feat(11-04): ship SystemAlertsBanner Livewire SFC + layout slot)
5. **Task 3:** `5f9a450` (test(11-04): add BoundaryArchTest noFacadeCallsFromCoreConsoleCommands + HorizonForceFlagTest)

Task 3 ships as a single commit because both invariants pass immediately against the current substrate (Wave 0/1/2 outputs were already facade-clean; `config/horizon.php` has no `force` key on any supervisor). The conventional TDD RED → GREEN split would require artificially regressing a Core command into using a facade or temporarily editing the Horizon config; the invariant's binding power is established by the explicit grep + comment-strip + regex shape, not by a fail-then-pass dance.

## Files Created/Modified

### Created

- `Modules/Core/Internal/Console/Support/DurationParser.php` — Pure-logic value object; instance method `subFromNow(string, CarbonImmutable): CarbonImmutable`; regex `^(\d+)([dhw])$`; `m` token explicitly rejected; throws InvalidArgumentException with the canonical grammar message on any invalid input.
- `Modules/Core/Internal/Console/FailedJobsCommand.php` — `final class` extending `Command`; signature `diederik:failed-jobs {action=prune} {--older-than=30d} {--dry-run}`; constructor DI of `DatabaseManager`, `Clock`, `DurationParser`; private `prune()` helper; zero facade imports.
- `Modules/Core/Internal/Http/Livewire/SystemAlertsBanner.php` — `final class` extending Livewire `Component`; method-parameter DI on `render()` + `acknowledge()`; no constructor.
- `Modules/Core/Resources/views/livewire/system-alerts-banner.blade.php` — Wrapper + `@foreach` + three explicit severity branches with literal Tailwind class strings; per-row layout flex + tabular-nums metadata; dismiss button with `wire:click="acknowledge({{ $alert->id }})"` + `aria-label="Mark system alert #{{ $alert->id }} as resolved"`.
- `Modules/Core/Resources/views/livewire/partials/system-alert-message.blade.php` — Kind-keyed message templates for backup_corrupt / backup_overdue / wal_mode_missing / synchronous_misconfigured; unknown kinds render the row's own `message` column; inline `<code>` styling for the artisan command references in warning rows.
- `Modules/Core/tests/Unit/DurationParserTest.php` — 16 Pest dataset scenarios (6 valid, 10 invalid).
- `Modules/Core/tests/Feature/FailedJobsCommandTest.php` — 5 Pest scenarios: dry-run preview, live prune, invalid token, unknown action, default-action.
- `Modules/Core/tests/Feature/SystemAlertsBannerTest.php` — 6 Pest scenarios: calm state, critical render + aria-label, acknowledge + re-render, cross-user isolation + system-wide rows, cross-user acknowledge raising NotFoundHttpException, severity-first DOM order.
- `tests/Contracts/HorizonForceFlagTest.php` — Standalone Pest file; one `it(...)` block reads `config/horizon.php` and asserts the comment-stripped contents do not match `/['"]force['"]\s*=>\s*true/`.

### Modified

- `Modules/Core/Providers/CoreServiceProvider.php` — Added imports for `FailedJobsCommand` and `SystemAlertsBanner`; appended `FailedJobsCommand::class` to the `commands([…])` array in `boot()`; added `$livewire->component('core.system-alerts-banner', SystemAlertsBanner::class)` in `boot()`.
- `resources/views/layouts/app.blade.php` — Added one new line `@livewire('core.system-alerts-banner')` inside the existing `@auth` block after `@livewire('core.top-nav')`.
- `tests/Contracts/BoundaryArchTest.php` — Appended one new `it(...)` block (`noFacadeCallsFromCoreConsoleCommands`) after the existing `systemAlertsTableNotJoinedToTransactions` invariant; same RecursiveIteratorIterator + comment-strip + namespace-grep shape as the Phase 9/10 invariants.

## Decisions Made

See frontmatter `key-decisions`. The substantive decisions:

1. **`m` token rejection on DurationParser** — RESEARCH Open Question #2 disposition; the narrower grammar means every consumer of the parser knows exactly which unit they got. The exception message names the regex verbatim so the operator can see the supported tokens in the error output.

2. **Default arm on the DurationParser match expression** — even though the regex character class constrains `$unit` to `d|h|w`, PHPStan's match-exhaustiveness analysis treats the `strtolower($matches[2])` return type as `non-empty-string` rather than the literal-string union. The default arm re-throws the same InvalidArgumentException so the rejection path stays uniform regardless of whether the regex or the match falls through.

3. **Cross-user safety lives on the action, not the Livewire component** — the SystemAlertsBannerTest scenario (e) tests `AcknowledgeSystemAlert` directly via `$this->app->make()` rather than `Livewire::actingAs($a)->test(…)->call('acknowledge', $bId)` because Livewire 4 catches Symfony HTTP exceptions during synthetic `->call()` invocations and reports them as Livewire status codes. The architectural guarantee is on the action; Livewire just wires the click handler.

4. **Blade comment phrasing for the `{!! ` grep gate** — the original Blade view comments contained the literal `{!! !!}` token to document the no-raw-output convention; this tripped the acceptance criterion `grep -c '{!! ' …` (which doesn't know about Blade-comment context). Comments rephrased to "unescaped Blade output is forbidden" so the gate continues to pass once future contributors run the same audit.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 — Blocking] `$this->artisan('diederik:failed-jobs prune', ...)` argument syntax rejected by Symfony**

- **Found during:** Task 1 GREEN verification (FailedJobsCommandTest's first scenario)
- **Issue:** The plan's `<behavior>` block on Task 1 shows the manual-smoke command as `php artisan diederik:failed-jobs prune --older-than=30d --dry-run`. Translating that into Pest's `$this->artisan('diederik:failed-jobs prune', [...])` form throws `Symfony\Component\Console\Exception\CommandNotFoundException: The command "diederik:failed-jobs prune" does not exist.` — Pest's artisan helper parses the first argument as the command name only; subcommand-style arguments must be passed in the options array as `['action' => 'prune', ...]`.
- **Fix:** Rewrote all four `$this->artisan(...)` invocations in the test file to pass `'action' => 'prune'` (or `'action' => 'whatever'` for the unknown-action scenario) in the arguments array. The bare-default test scenario (the 5th one) drops the action argument entirely so the test exercises the `{action=prune}` signature default.
- **Files modified:** Modules/Core/tests/Feature/FailedJobsCommandTest.php
- **Verification:** All 5 FailedJobsCommandTest scenarios pass after the fix.
- **Committed in:** 73c81cd (Task 1 GREEN)

**2. [Rule 1 — Bug] PHPStan match-exhaustiveness rejected DurationParser's match arms**

- **Found during:** Task 1 GREEN (running phpstan on the new files)
- **Issue:** DurationParser's `match ($unit) { 'd' => …, 'h' => …, 'w' => … }` triggered `match.unhandled: Match expression does not handle remaining value: non-empty-string`. The character class `[dhw]` in the regex constrains `$unit` to those three literals, but PHPStan's narrowing rule infers the post-`strtolower()` value as `non-empty-string` rather than `'d'|'h'|'w'`.
- **Fix:** Added a `default => throw new InvalidArgumentException(...)` arm that re-throws the same canonical grammar message. The runtime is structurally unreachable (the regex guard above rules it out), but the explicit default keeps PHPStan happy and preserves the rejection-path symmetry.
- **Files modified:** Modules/Core/Internal/Console/Support/DurationParser.php
- **Verification:** phpstan exits 0; all 16 DurationParserTest scenarios still pass (no behavioural change because the default arm is unreachable when the regex guard runs first).
- **Committed in:** 73c81cd (Task 1 GREEN)

**3. [Rule 1 — Bug] PHPStan flagged mixed-to-int/string casts on dry-run row print**

- **Found during:** Task 1 GREEN (phpstan after the dry-run printer landed)
- **Issue:** The dry-run printer loop reads `$row->id`, `$row->queue`, `$row->failed_at` from the raw query-builder `stdClass` rows. Each property is `mixed` from PHPStan's view, and casting `mixed` to `int`/`string` trips `cast.int` / `cast.string` in the strict-rules profile.
- **Fix:** Replaced the raw casts with `is_numeric($row->id) ? (int) $row->id : 0` + `is_string($row->queue) ? $row->queue : ''` + `is_string($row->failed_at) ? $row->failed_at : ''`. The narrowing keeps the sprintf signature happy and preserves the documented row-print format.
- **Files modified:** Modules/Core/Internal/Console/FailedJobsCommand.php
- **Verification:** phpstan exits 0; the dry-run scenario's expected output (`Would delete 3 rows`) still appears.
- **Committed in:** 73c81cd (Task 1 GREEN)

**4. [Rule 1 — Bug] PHPStan flagged the `(string) $this->argument('action')` cast as useless**

- **Found during:** Task 1 GREEN (phpstan re-run after fix #3)
- **Issue:** Larastan introspects `Command::argument('action')` and reports its return type as `string` (because the `{action=prune}` signature has a default), so the defensive `(string)` cast on `$this->argument('action')` trips `cast.useless`.
- **Fix:** Dropped the cast. The `$action` variable is typed `string` directly. The `Unknown action:` error path's sprintf still works because `$action` is always string at that point.
- **Files modified:** Modules/Core/Internal/Console/FailedJobsCommand.php
- **Verification:** phpstan exits 0; the unknown-action scenario still asserts `Unknown action: whatever` correctly.
- **Committed in:** 73c81cd (Task 1 GREEN)

**5. [Rule 1 — Bug] PHPStan flagged `$this->option('older-than')` as `string|null`**

- **Found during:** Task 1 GREEN (phpstan re-run after fix #4)
- **Issue:** `$this->option('older-than')` returns `string|null` per Larastan even though the signature has a default `30d`. Passing the value straight into `DurationParser::subFromNow(string $input, ...)` trips `argument.type: string|null given`.
- **Fix:** Coalesced the option read with an empty-string default: `$token = $this->option('older-than') ?? '';`. The empty string then flows into the parser and trips the canonical grammar error message, which is the right behaviour if a future signature edit drops the default.
- **Files modified:** Modules/Core/Internal/Console/FailedJobsCommand.php
- **Verification:** phpstan exits 0; all 5 FailedJobsCommandTest scenarios still pass.
- **Committed in:** 73c81cd (Task 1 GREEN)

**6. [Rule 1 — Bug] Cross-user acknowledge test rewritten to call action directly**

- **Found during:** Task 2 GREEN verification (SystemAlertsBannerTest scenario (e) — `it('refuses cross-user acknowledge attempts via the action …')`)
- **Issue:** The plan's `<behavior>` block on Task 2 calls for a `Livewire::actingAs($a)->test(…)->call('acknowledge', $bId)` invocation that should `toThrow(NotFoundHttpException::class)`. Livewire 4 catches Symfony HTTP exceptions during synthetic `->call()` and reports them as a Livewire status code rather than re-raising — so `expect(fn () => $component->call('acknowledge', $bId))->toThrow(...)` never fires.
- **Fix:** Rewrote the scenario to invoke `AcknowledgeSystemAlert` directly via `$this->app->make(AcknowledgeSystemAlert::class)($bId, $this->userA)` — matches the established pattern across Phase 5 ConfirmChainLinkTest, Phase 7 RejectChainLinkTest, Phase 9 DriftAlertCrossUser404Test, and Phase 9 RuleFormModalTest. The architectural guarantee belongs on the action (the cross-user 404 lives in AcknowledgeSystemAlert::__invoke()), so the test directly exercises the boundary that ships the safety.
- **Files modified:** Modules/Core/tests/Feature/SystemAlertsBannerTest.php
- **Verification:** the scenario now passes; `NotFoundHttpException` raised + row's `acknowledged_at` is asserted to remain NULL.
- **Committed in:** ec2f439 (Task 2 GREEN)

**7. [Rule 1 — Bug] Blade view + partial comments contained `{!! !!}` literal tripping the grep gate**

- **Found during:** Task 2 GREEN acceptance-criteria audit (`grep -c '{!! ' Modules/Core/Resources/views/livewire/system-alerts-banner.blade.php` returned 1)
- **Issue:** The Blade view and the kind-message partial each contained a comment block referencing `{!! !!}` as the forbidden raw-output syntax. The plan's acceptance criterion `grep -c '{!! ' = 0` doesn't know the occurrence is inside a Blade comment; it only sees the literal byte sequence. Failing the grep is acceptable because the intent (no XSS-via-raw-output) is documented by the comment, but the gate is structural.
- **Fix:** Rephrased both comments to "Unescaped output (raw-output Blade) is forbidden" / "unescaped Blade output is forbidden". The XSS guard is documented at the same strength without using the literal forbidden syntax.
- **Files modified:** Modules/Core/Resources/views/livewire/system-alerts-banner.blade.php, Modules/Core/Resources/views/livewire/partials/system-alert-message.blade.php
- **Verification:** `grep -c '{!! ' …` returns 0 on both files; all 6 SystemAlertsBannerTest scenarios still pass.
- **Committed in:** ec2f439 (Task 2 GREEN)

**8. [Rule 1 — Bug] Pint normalised `new DurationParser()` → `new DurationParser` and fully-qualified InvalidArgumentException**

- **Found during:** Task 1 GREEN (running `./vendor/bin/pint` on the test file)
- **Issue:** Pint flagged `new_with_parentheses` (parentheses on empty-argument constructors) and `fully_qualified_strict_types` (the test imported InvalidArgumentException via a non-compound `use`). The latter also triggered a PHP warning during Pest's discovery phase about the use statement having no effect on the global class.
- **Fix:** Ran `./vendor/bin/pint` to apply both fixers automatically — removed the empty parentheses on `new DurationParser` and replaced the bare `InvalidArgumentException::class` reference with the fully-qualified `\InvalidArgumentException::class` (and dropped the corresponding `use InvalidArgumentException` import).
- **Files modified:** Modules/Core/tests/Unit/DurationParserTest.php
- **Verification:** pint exits 0; PHP no longer emits the use-statement warning during Pest discovery.
- **Committed in:** 73c81cd (Task 1 GREEN)

**9. [Rule 1 — Bug] Pint normalised single-quote / backslash-escape on FailedJobsCommandTest**

- **Found during:** Task 1 GREEN (running pint)
- **Issue:** Pint's `single_quote` fixer prefers single-quoted strings over double-quoted strings when the content has no interpolation. The test's `expectsOutputToContain("Duration must match /^\\d+[dhw]\$/")` was double-quoted; pint rewrote it to single-quoted with simpler backslash semantics.
- **Fix:** Pint applied the fix automatically. The expected-output string is byte-identical after the rewrite; the regex characters survive intact because single-quoted strings don't perform PHP interpolation.
- **Files modified:** Modules/Core/tests/Feature/FailedJobsCommandTest.php
- **Verification:** pint exits 0; all 5 FailedJobsCommandTest scenarios still pass.
- **Committed in:** 73c81cd (Task 1 GREEN)

**10. [Rule 1 — Bug] Pint normalised BoundaryArchTest formatting**

- **Found during:** Task 3 (running pint on the appended `it(...)` block)
- **Issue:** Pint's `single_quote`, `unary_operator_spaces`, and `not_operator_with_successor_space` fixers normalised quoting + spacing on the new block. Pint had to be run on the entire file because the fixers may also touch lines outside the new block.
- **Fix:** Ran `./vendor/bin/pint tests/Contracts/BoundaryArchTest.php`. The applied changes were stylistic only; the new invariant's logic is unchanged.
- **Files modified:** tests/Contracts/BoundaryArchTest.php
- **Verification:** pint exits 0; the full Contracts suite passes (104 tests, no regression on any of the 102 pre-existing invariants).
- **Committed in:** 5f9a450 (Task 3)

---

**Total deviations:** 10 auto-fixed (8 Rule 1 — Bug, 1 Rule 3 — Blocking, 1 stylistic pint chain rolled up as Rule 1)

**Impact on plan:** All 10 deviations are driven by the project's CI-enforced larastan-strict-rules + Pint profile, plus Pest 3's artisan-argument syntax. None changes scope; none changes architecture; none adds packages. The deviations preserve the plan's verbatim intent (per-row severity branches with literal Tailwind classes, cross-user 404 on the action layer, DI-only Core console commands, no Horizon force flag) while satisfying the static-analysis + acceptance-criteria gates.

## Authentication Gates Encountered

None — Phase 11-04 ships entirely local infrastructure (artisan command, Livewire SFC, arch tests). No OAuth or third-party credential flow.

## Issues Encountered

**Worktree CWD vs. PHPUnit testsuite-path discovery (carry-forward from 11-01 / 11-02 / 11-03).** Pest's BootFiles bootstrapper loads `tests/Pest.php` from the rootPath derived from the realpath of `vendor/autoload.php`. The worktree at `.claude/worktrees/agent-aa3ac587a5b177d85/` has no `vendor/` directory of its own; Pest cannot run against the worktree CWD. For each verification round during this plan, the modified files were `cp`-ed into the matching main-repo paths, Pest + Larastan + Pint were run from the main repo, then the main repo's tracked files were `git checkout --` reverted and the untracked files removed. The main repo's working tree returned to its `?? .claude/` + `?? storage/app/inbox/` baseline after every cycle. All commits in this plan live in the worktree branch only.

**Pest cross-suite test-file double-loading warnings.** Running `./vendor/bin/pest --filter='DurationParserTest|FailedJobsCommandTest'` without a `--testsuite` filter caused Pest to register the same DriftAlerts test files against both the `Unit` testsuite (which includes `Modules/DriftAlerts/tests/Unit`) and the standalone `DriftAlerts` testsuite (which globs `Modules/DriftAlerts/tests` directly). The warnings cascaded into a fatal `Cannot redeclare function rpSeries()` from the per-Module Pest dataset helpers. Resolved by always passing `--testsuite=Unit` / `--testsuite=Feature` / `--testsuite=Contracts` when running filter-narrow Pest invocations during this plan's verification cycles. This is an artifact of the project's existing `phpunit.xml` testsuite topology — out of scope for this plan to fix.

## User Setup Required

None — no new dependencies, no new environment variables, no external service configuration.

The new `diederik:failed-jobs prune --older-than=30d --dry-run` command is safe to run on a fresh checkout: the Laravel-managed `failed_jobs` table exists from the substrate Phase 5 migration, and a dry-run pass against an empty table prints `Would delete 0 rows (--dry-run; nothing written).` and exits 0.

The SystemAlertsBanner Livewire SFC starts surfacing rows on the next authenticated page-load once any of the four `kind` values lands in `system_alerts` (via 11-02's `db:backup` corruption path, via 11-03's `BackupFreshnessProbe` overdue path, or via 11-03's `HealthCheckServiceProvider` PRAGMA-drift path). On a calm DB the banner renders only the empty wrapper — no visible chrome, no copy.

## Next Phase Readiness

- **Phase 12+:** The `SystemAlertsBanner` is the canonical operational-failure surface. Any future module that writes a `system_alerts` row of a known kind (`backup_corrupt`, `backup_overdue`, `wal_mode_missing`, `synchronous_misconfigured`) gets a banner row on next page load for free. New kinds fall through to the row's own `$alert->message` column — no Blade change required for v1 surfacing, although introducing a new copywriting template is a one-`@case` addition to the partial.
- **Phase 12+:** The two arch invariants are immediate guards. Any future contributor adding `use Illuminate\Support\Facades\…` to a file under `Modules/Core/Internal/Console/`, or flipping any Horizon supervisor to `'force' => true`, fails the Contracts test suite at PR time. The failure messages name the offender file and the violated rule directly.
- **Phase 11 vertical UX:** With the banner in place, Phase 11 is feature-complete from the operator's perspective. The user can see, dismiss, and depend on alerts; the failed_jobs table is maintainable from CLI; the operational doctor + backup + restore + boot-check surfaces all converge on the same banner.

## Known Stubs

None — every shipped surface is wired end-to-end. The empty-state Blade wrapper is intentional (UI-SPEC §State Matrix locked invisible-when-calm; future "no alerts" placeholder explicitly rejected during context-gathering). The `info` severity branch in the Blade view is rendered identically to the warning branch's chrome with the slate token set; no `info` kind is wired in v1, but the branch is present so a future module can write an info-severity row without an additional Blade change.

## Self-Check: PASSED

- File `Modules/Core/Internal/Console/Support/DurationParser.php` exists in worktree.
- File `Modules/Core/Internal/Console/FailedJobsCommand.php` exists in worktree.
- File `Modules/Core/Internal/Http/Livewire/SystemAlertsBanner.php` exists in worktree.
- File `Modules/Core/Resources/views/livewire/system-alerts-banner.blade.php` exists in worktree.
- File `Modules/Core/Resources/views/livewire/partials/system-alert-message.blade.php` exists in worktree.
- File `Modules/Core/tests/Unit/DurationParserTest.php` exists in worktree.
- File `Modules/Core/tests/Feature/FailedJobsCommandTest.php` exists in worktree.
- File `Modules/Core/tests/Feature/SystemAlertsBannerTest.php` exists in worktree.
- File `tests/Contracts/HorizonForceFlagTest.php` exists in worktree.
- Modified `Modules/Core/Providers/CoreServiceProvider.php` (FailedJobsCommand + SystemAlertsBanner registration).
- Modified `resources/views/layouts/app.blade.php` (one new line in @auth block).
- Modified `tests/Contracts/BoundaryArchTest.php` (one new `it(...)` block appended).
- Commits `b8d4c7f`, `73c81cd`, `aab64fe`, `ec2f439`, `5f9a450` all reachable from worktree HEAD.
- `pest --testsuite=Unit --filter='DurationParserTest'` → 16 passed (26 assertions).
- `pest --testsuite=Feature --filter='FailedJobsCommandTest|SystemAlertsBannerTest'` → 11 passed (32 assertions).
- `pest --testsuite=Contracts --filter='HorizonForceFlagInvariant|noFacadeCallsFromCoreConsoleCommands'` → 2 passed (2 assertions).
- `pest --testsuite=Contracts` (full suite from main-repo verification cycle) → 104 passed (591 assertions; up from 102 in Wave 2).
- `phpstan analyse --memory-limit=2G <all touched .php files>` → No errors.
- `pint --test <all touched files>` → passed.
- Manual smoke `php artisan list | grep diederik:failed-jobs` → registered with the documented description.
- `grep -c 'wire:click="acknowledge(' Modules/Core/Resources/views/livewire/system-alerts-banner.blade.php` → 3 (one per severity branch).
- `grep -c '{!! ' Modules/Core/Resources/views/livewire/system-alerts-banner.blade.php` → 0 (XSS guard).
- `grep -c '{!! ' Modules/Core/Resources/views/livewire/partials/system-alert-message.blade.php` → 0 (XSS guard).
- `grep -c 'border-rose-500' Modules/Core/Resources/views/livewire/system-alerts-banner.blade.php` → 1 (critical branch literal class present).
- `grep -c 'border-amber-300' Modules/Core/Resources/views/livewire/system-alerts-banner.blade.php` → 1 (warning branch literal class present).
- `grep -c 'border-slate-200' Modules/Core/Resources/views/livewire/system-alerts-banner.blade.php` → 1 (info branch literal class present).
- `grep -cE 'border-(rose|amber|slate)-\{' Modules/Core/Resources/views/livewire/system-alerts-banner.blade.php` → 0 (no dynamic Tailwind class interpolation).
- `grep -c 'core.system-alerts-banner' resources/views/layouts/app.blade.php` → 1.
- `grep -c 'public function __construct' Modules/Core/Internal/Http/Livewire/SystemAlertsBanner.php` → 0 (no constructor DI on Livewire component).
- `grep -c 'use Illuminate\\Support\\Facades\\' Modules/Core/Internal/Console/FailedJobsCommand.php` → 0 (no facade imports).
- `grep -c 'noFacadeCallsFromCoreConsoleCommands' tests/Contracts/BoundaryArchTest.php` → 1 (the new it() block title; legal after the same comment-strip the invariant itself applies).

---
*Phase: 11-operational-hardening*
*Completed: 2026-05-19*
