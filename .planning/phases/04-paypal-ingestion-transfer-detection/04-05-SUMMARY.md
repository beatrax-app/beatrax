---
phase: 04-paypal-ingestion-transfer-detection
plan: 05
subsystem: planning-and-contracts
tags: [documentation, deferral, boundary-arch, ing-09, phase-close-out, d-79]

# Dependency graph
requires:
  - phase: 04-paypal-ingestion-transfer-detection
    provides: Wave 1 CSV-only PayPal ingestion path live (04-02)
  - phase: 04-paypal-ingestion-transfer-detection
    provides: Wave 2 transfer-pair backbone live (04-03)
  - phase: 04-paypal-ingestion-transfer-detection
    provides: Wave 3 income demoability + manual override live (04-04)
provides:
  - ".planning/ROADMAP.md — Phase 4 SC #2 rewritten + Phase 4 Goal line tightened to reflect ING-09 deferral"
  - ".planning/REQUIREMENTS.md — ING-09 active entry rewritten; new 'Deferred / Future-Revisit (Phase 4 close-out)' section added with the business-account-upgrade trigger; traceability row flipped Pending -> Deferred"
  - "tests/Contracts/BoundaryArchTest.php — noPaypalApiRoute arch invariant scans routes/ + Modules/ for PaypalApiAdapter / PaypalReportingApi / paypal-api with comment-stripping so PHPDoc references stay legal"
affects: [05-*, 06-*, 07-*]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Deferred-requirement documentation pattern: (1) active requirement entry is rewritten to point at the Deferred section rather than deleted (so REQ-ID stays discoverable and the traceability count stays stable); (2) a dedicated 'Deferred / Future-Revisit' section sits between the v2 Requirements block and the Out of Scope block (distinct from both — v2 is post-v1 ambitions, Out of Scope is permanent exclusion, Deferred is in-scope-but-blocked); (3) every Deferred row carries an explicit trigger sentence so the revisit condition is unambiguous; (4) traceability status flips Pending -> Deferred, never to Complete and never to a third 'Deferred-Complete' state."
    - "Boundary arch invariant via file-scan: when a Pest pest-plugin-arch `arch(...)` rule cannot express the constraint (e.g. 'literal-string class names that don't exist yet must never exist'), drop down to an `it(...)` test that walks `routes/` + `Modules/` with `RecursiveIteratorIterator`, strips `/* ... */` + `// ...` comments via regex, then asserts the literal-pattern regex finds zero hits. The comment-stripping is load-bearing: legitimate PHPDoc references like `/** ING-09 is deferred */` MUST stay legal, otherwise the arch test becomes a documentation tax."
    - "Phase 4 close-out posture: Wave 4 ships zero user-visible behaviour — purely docs + arch invariant. The convention going forward is that any deferred requirement gets a tight close-out plan in the SAME phase, not bumped to the next phase: doc-only deferrals are cheap, and the close-out plan locks the deferral with an enforceable invariant (the arch test) so future phases can't accidentally violate it."

key-files:
  created: []
  modified:
    - .planning/ROADMAP.md (Phase 4 Goal + Phase 4 SC #2 rewritten)
    - .planning/REQUIREMENTS.md (ING-09 active entry rewritten + Deferred / Future-Revisit section added + traceability row flipped to Deferred)
    - tests/Contracts/BoundaryArchTest.php (noPaypalApiRoute arch test appended)

key-decisions:
  - "Deferral-documentation pattern locks at the Phase 4 close-out (D-79 implementation). The deferred requirement is NOT deleted from the active list and NOT silently checked off; instead it is rewritten to reference the new section and the traceability status flips Pending -> Deferred. Reasoning: (a) the REQ-ID stays discoverable for any future search; (b) the traceability count (68 / 68 mapped, 100%) stays stable; (c) the trigger condition is preserved verbatim alongside the requirement text, so a future maintainer reading just the active list still sees the deferral note inline."
  - "Arch test uses `it(...)` Pest syntax with a manual RecursiveIteratorIterator + regex scan rather than `arch(...)` from pest-plugin-arch. Reason: pest-plugin-arch can express 'class X must not be used in namespace Y' but cannot express 'class X / Y / Z must not exist anywhere' for class names that don't exist yet. The file-walk + comment-strip approach is the same shape `UserIdColumnArchTest.php` already uses for schema-introspection invariants, so the codebase already has the pattern."
  - "Test-file location: the new test sits in `tests/Contracts/BoundaryArchTest.php` (alongside the other module-boundary `arch(...)` rules) rather than in a new file like `tests/Contracts/NoPaypalApiRouteTest.php`. Reason: it is conceptually a boundary rule (module / route / class layer never violates the deferral) and grouping it next to the existing boundary tests keeps the 'where do arch invariants live?' answer simple — one file."
  - "Comment-stripping in the arch test scans `/* ... */` and `// ...` via a single regex pass before the literal-pattern check. Verified by spot-check against six representative strings (production patterns, comment patterns, legitimate `PaypalCsvAdapter`): regex hits the three production forms, misses the legitimate adapter, misses comment-wrapped references. The comment-strip is load-bearing — without it, this very SUMMARY.md (and the Wave 4 plan / context docs) would trip the test if `.planning/` were ever added to the scan scope."
  - "Test scope is `routes/` + `Modules/` only. `.planning/` is intentionally excluded — the planning docs reference ING-09 / paypal-api as part of legitimate Wave 4 wording. `tests/` is also excluded — the new arch test itself contains the literal `paypal-api` string in its regex AND in its error message; including `tests/` in the scan scope would create a self-referential failure."
  - "Validation strategy: test passes against the current codebase (no PayPal-API code exists in production paths) AND was proven to fail correctly via a temporary sentinel file (`class PaypalApiAdapter {}` dropped into the Paypal/ directory, sentinel removed before commit). The toggling exercise verified both directions of the regex without committing the sentinel — same pattern the plan's `<behavior>` block describes."

patterns-established:
  - "Phase close-out plan pattern: when a phase scope has a deferred requirement (i.e. a REQ-ID that the phase research determined cannot be delivered under current constraints), the final wave of the phase is a small documentation-only plan that (1) edits ROADMAP + REQUIREMENTS to mark the deferral, (2) adds an enforceable arch invariant that guards against accidental re-introduction. This is the model future phases inherit when they ship something less than the originally-scoped requirement list."
  - "Defensive arch invariant via comment-stripped grep: any future deferred / out-of-scope feature can use the same shape — append an `it(...)` to `BoundaryArchTest.php` that scans `routes/` + `Modules/` for the forbidden literal patterns, strips comments first, fails loudly with a path list if matches appear. This is one of the cheapest available arch-rule shapes; the project now has the pattern documented and one production instance."

requirements-completed:
  - "ING-09 (PayPal Reporting API / Transaction Search via OAuth2) — Deferred. Trigger: when the user upgrades to a PayPal Business account, revisit. Not 'Complete' in the traceability sense — the requirement is acknowledged-and-blocked, not delivered. The CSV ingestion path (ING-05) covers the same data shape without API gating, and ING-05 IS complete (delivered in 04-02)."

# Metrics
metrics:
  duration: "~2min"
  tasks_completed: 1
  files_created: 0
  files_modified: 3
  commits: 1
  date_completed: 2026-05-16
---

# Phase 4 Plan 05: Wave 4 ING-09 Deferral Close-Out Summary

**One-liner:** Phase 4 closes cleanly: SC #2 rewritten to reflect ING-09's
deferral behind a business-account upgrade trigger, a new Deferred /
Future-Revisit section in REQUIREMENTS locks the trigger inline with
the requirement text, and `BoundaryArchTest::noPaypalApiRoute` provides
a defensive grep-based arch invariant that fails the suite if a future
task accidentally lands a `PaypalApiAdapter` / `PaypalReportingApi` /
`paypal-api` shape under `routes/` or `Modules/`.

## Verbatim before/after

### ROADMAP.md Phase 4 Goal line

**Before:**
> User can import PayPal activity — via CSV (canonical) and optionally
> the PayPal Reporting API — with the event-log rolled up into a single
> canonical transaction per payment, and have ASN↔ICS / PayPal↔bank
> moves correctly flagged as internal transfers rather than income.

**After:**
> User can import PayPal activity — via CSV (canonical; the Reporting
> API path is deferred behind a business-account trigger per ING-09) —
> with the event-log rolled up into a single canonical transaction per
> payment, and have ASN↔ICS / PayPal↔bank moves correctly flagged as
> internal transfers rather than income.

### ROADMAP.md Phase 4 SC #2

**Before:**
> 2. User can optionally authorize PayPal via OAuth2 and pull recent
>    activity directly through the Reporting API, with the CSV path
>    remaining as the supported fallback

**After:**
> 2. PayPal Reporting API integration is documented as deferred behind
>    a business-account upgrade trigger (see REQUIREMENTS.md "Deferred
>    / future-revisit" → ING-09); CSV remains the supported PayPal
>    ingestion path

### REQUIREMENTS.md ING-09 active entry

**Before:**
> [ ] **ING-09**: PayPal Reporting API (Transaction Search) is
> supported as an optional alternative to CSV upload; user authorizes
> via OAuth2 and the app pulls activity directly. Phase research
> verifies feasibility for the user's account type (personal vs
> business). CSV path remains as the supported fallback in case
> Transaction Search is gated behind a business account.

**After:**
> [ ] **ING-09**: Moved to "Deferred / future-revisit" section below.
> PayPal Transaction Search is gated behind a business account; the
> user is on a personal account. CSV path (ING-05) remains the
> supported PayPal ingestion entry.

### REQUIREMENTS.md new Deferred section

```markdown
## Deferred / Future-Revisit (Phase 4 close-out)

Requirements scoped to a v1 phase but deferred to a future-revisit
window pending a clear trigger. Distinct from the v2 Requirements
section above (v2 is "post-v1 ambitions"); Deferred is "in-scope but
blocked".

| REQ-ID | Description | Trigger |
|--------|-------------|---------|
| ING-09 | PayPal Reporting API (Transaction Search) via OAuth2 | When the user upgrades to a PayPal Business account, revisit. The CSV ingestion path (ING-05) covers the same data without API gating. |
```

### REQUIREMENTS.md traceability row

**Before:**
> | ING-09 | Phase 4 | Pending |

**After:**
> | ING-09 | Phase 4 | Deferred |

## Performance

- **Duration:** ~2 minutes
- **Started:** 2026-05-15T22:39:07Z
- **Tasks:** 1
- **Files created:** 0
- **Files modified:** 3

## Accomplishments

- **Phase 4 close-out documented:** ROADMAP Phase 4 SC #2 + Goal line
  reflect ING-09's deferral; the previous "optionally authorize PayPal
  via OAuth2 and pull recent activity directly through the Reporting
  API" claim is replaced with explicit deferral wording and a pointer
  to the new Deferred section.
- **Deferred / Future-Revisit section locked in REQUIREMENTS.md:**
  ING-09 row with the business-account-upgrade trigger sits between
  the v2 Requirements block and the Out of Scope block, distinct from
  both. Future deferred requirements from any phase extend this same
  section.
- **`BoundaryArchTest::noPaypalApiRoute` defensive arch invariant:**
  walks `routes/` + `Modules/` recursively, strips comments, asserts
  the regex `/PaypalApiAdapter|PaypalReportingApi|paypal-api/i` finds
  zero hits in production code. Comment-stripping means legitimate
  PHPDoc references stay legal. Phase 5+ planners see the test fail
  immediately if a stray scaffold lands under those literal patterns.
- **Phase 4 close-out gate met:** all four SCs from the rewritten
  ROADMAP §Phase 4 are either GREEN (SC #1 from 04-02; SC #3 from
  04-03; SC #4 from 04-04) or documented-as-deferred-with-trigger
  (SC #2 from 04-05).

## Task Commits

1. **Task 1: ROADMAP + REQUIREMENTS deferral edit + BoundaryArchTest::noPaypalApiRoute**
   - `c1991a4` (feat) — defer ING-09 + add noPaypalApiRoute arch invariant

A single TDD cycle for the arch test was exercised via a temporary
sentinel file rather than a separate test-then-implement commit: the
arch test's invariant ALREADY holds against the codebase (no PayPal
API code exists in production paths), so the canonical RED step would
have committed a sentinel just to verify failure-mode. Instead the
RED step was performed locally (sentinel dropped, suite re-run,
verified FAIL with the expected message, sentinel removed) before the
real commit — same defence-in-depth the plan's `<behavior>` Test 2
describes. The single feat commit bundles the arch test + the doc
edits because all three artefacts move together as one logical change.

## Files Modified

- `.planning/ROADMAP.md` — Phase 4 Goal line + Phase 4 SC #2 wording
  rewritten. Other Phase 4 SCs (1, 3, 4), Phase 4 Requirements list
  (`ING-05, ING-09, LED-04, LED-05`), and every other Phase's section
  preserved verbatim.
- `.planning/REQUIREMENTS.md` — ING-09 active-list line rewritten to
  point at the new section; new `## Deferred / Future-Revisit (Phase
  4 close-out)` section added between v2 Requirements and Out of
  Scope; traceability table row for ING-09 flipped Pending -> Deferred.
  Every other requirement entry, v2 block, Out of Scope block, and
  traceability row preserved verbatim.
- `tests/Contracts/BoundaryArchTest.php` — `noPaypalApiRoute`
  `it(...)` test appended after the existing `arch(...)` rules.
  Existing nine arch rules untouched; the new test uses the same
  `RecursiveIteratorIterator` + comment-strip + literal-regex pattern
  any future deferred-feature arch invariant inherits.

## Decisions Made

See the `key-decisions` frontmatter array. Highlights:

1. **Deferred requirement stays in the active list AND in the new
   Deferred section** — the active line is rewritten to reference the
   section rather than removed, so the REQ-ID stays discoverable and
   the traceability count (68 / 68 mapped) stays stable. Status flips
   Pending -> Deferred (not Complete and not a third "Deferred-Complete"
   state — Deferred is its own terminal status for this requirement
   in v1).
2. **Arch test uses `it(...)` Pest syntax with `RecursiveIteratorIterator`
   + comment-strip regex**, not `arch(...)` from pest-plugin-arch.
   Reason: pest-plugin-arch expresses "class X must not be used in
   namespace Y" but cannot express "class names X / Y / Z must not
   exist anywhere" for class names that don't exist yet. The
   file-walk shape mirrors `UserIdColumnArchTest.php`'s
   schema-introspection invariant.
3. **Comment-stripping is load-bearing.** The regex hits the three
   forbidden literal patterns (`PaypalApiAdapter`, `PaypalReportingApi`,
   `paypal-api`) in production code but ignores them inside `/* ... */`
   and `// ...` comments. Verified by hand-rolled spot-check against
   six representative strings before commit.
4. **Scope is `routes/` + `Modules/` only.** `.planning/` is
   intentionally out — the planning docs reference ING-09 / paypal-api
   as part of legitimate Wave 4 wording. `tests/` is also out — the
   arch test itself contains the literal `paypal-api` string in its
   regex AND its error message; including `tests/` would create a
   self-referential failure.

## Deviations from Plan

None substantive.

The plan's `<behavior>` block mentions a "single TDD cycle" with two
tests (Test 1 happy path + Test 2 negative case via sentinel toggle);
the plan's own text explicitly says "We do not actually create [the
sentinel] file; the test's failure case is exercised in the regex
itself — verified by toggling the assertion temporarily during
development if desired, then removing the toggle". This is exactly
the protocol followed: a sentinel file
(`_sentinel_paypal_api.php` under
`Modules/Ingestion/Internal/Adapters/Paypal/`) was dropped locally,
the suite re-run, the test failed with the expected path listing,
sentinel removed, suite re-run, test passed. The sentinel never
entered git history. The single feat commit (`c1991a4`) bundles the
arch test + ROADMAP + REQUIREMENTS edits because they move together
as one logical close-out unit.

### Auth gates

None.

## Pre-existing failure unchanged

The single deferred failure carried forward from Phase 4 Plan 03 —
`TransactionTypeTest::it rejects an invalid transaction type at the
DB layer` — remains unchanged. Logged in
`.planning/phases/04-paypal-ingestion-transfer-detection/deferred-items.md`.
Suite count went from 571 passed + 1 failed (after 04-04 GREEN) to
572 passed + 1 failed (after 04-05 GREEN). Net of Wave 4 work: +1
new GREEN test (`noPaypalApiRoute`), zero new failures, zero
regressions.

## Self-Check

### File existence
- `.planning/ROADMAP.md` — MODIFIED (verified `deferred` token present
  in Phase 4 SC #2; verified the new Goal line no longer claims
  "optionally the PayPal Reporting API" as a delivered capability)
- `.planning/REQUIREMENTS.md` — MODIFIED (verified ING-09 active line
  references the new section; verified `## Deferred / Future-Revisit
  (Phase 4 close-out)` section present; verified traceability row
  reads `| ING-09 | Phase 4 | Deferred |`)
- `tests/Contracts/BoundaryArchTest.php` — MODIFIED (verified
  `noPaypalApiRoute` test appended; verified `paypal-api` literal
  inside the regex)

### Commit existence
- `c1991a4` — feat(04-05): defer ING-09 + add noPaypalApiRoute arch invariant — FOUND

### Gate sequence (TDD plan-task verification)

This is a `type: execute` plan with one task carrying `tdd="true"`.
The TDD cycle was exercised end-to-end before commit: failure-mode
proved via temporary sentinel (RED-equivalent step), sentinel
removed, test verified GREEN against the intended invariant. The
single commit bundles arch test + doc edits because all three
artefacts represent a single atomic Phase 4 close-out unit; a
separate RED `test(...)` commit followed by a GREEN `feat(...)`
commit would have committed the sentinel just to demonstrate failure-mode,
which contradicts the plan's own behavior block ("We do not actually
create this file"). Documenting the TDD compliance shape under
`## Deviations from Plan` and here under Gate Sequence.

## TDD Gate Compliance

Per-task TDD discipline: the single task carried `tdd="true"` and the
RED step was performed via a local sentinel file
(`_sentinel_paypal_api.php` dropped under
`Modules/Ingestion/Internal/Adapters/Paypal/`, suite re-run, FAIL
verified with the expected path listed in the assertion message,
sentinel removed) before the commit. The plan's `<behavior>` block
explicitly endorses this approach ("verified by toggling the
assertion temporarily during development if desired, then removing
the toggle"). The single feat commit captures the verified GREEN
state of arch test + ROADMAP + REQUIREMENTS edits. No `test(...)`
RED commit exists for this plan because committing the sentinel
would have introduced production-tree contamination just to satisfy
the commit-shape convention.

### Quality gates

- `composer analyse` — exits 0 (Larastan level max + strict-rules + Livewire extension; 169 files)
- `composer format:check` — exits 0 (Pint: `{"tool":"pint","result":"passed"}`)
- `composer test` — 572 passed, 3 skipped, 3 notices, 1 failed.
  The single failure is the pre-existing
  `TransactionTypeTest::it rejects an invalid transaction type`
  carried forward from Wave 2 (`deferred-items.md`). Net of Wave 4
  work the suite GREENed 1 new test (`noPaypalApiRoute`) with zero
  regressions.

## Self-Check: PASSED

## Phase 4 Close-Out

All four Phase 4 Success Criteria (per the rewritten ROADMAP §Phase 4):

1. **SC #1 GREEN** — User can upload a PayPal activity CSV and see
   one transaction per payment with fees / holds / currency-conversion
   rows enriching that single row. Delivered in 04-02 (Wave 1 vertical
   slice).
2. **SC #2 DEFERRED-WITH-TRIGGER** — PayPal Reporting API integration
   is documented as deferred behind a business-account upgrade trigger;
   CSV remains the supported PayPal ingestion path. Locked by 04-05
   (Wave 4 close-out) + the `noPaypalApiRoute` arch invariant.
3. **SC #3 GREEN** — Internal moves between own accounts (ASN -> ICS,
   PayPal -> bank) appear as paired transfer-out / transfer-in rows
   linked via `pair_transaction_id` and never inflate income totals.
   Delivered in 04-03 (Wave 2 transfer-pair backbone) + locked by
   04-04 (Wave 3 income demoability + dashboard rollup type-filter
   contract).
4. **SC #4 GREEN** — Genuine income is flagged distinctly from
   internal transfers, with manual override available on the
   transaction detail page. Delivered in 04-04 (Wave 3 income
   demoability + Reclassify action with atomic break-pair invariant).

## Pointer to Phase 5

Phase 5 (Chain Resolution — PayPal Funding + ICS Bulk-iDEAL
Decomposition) reuses Phase 4's Layer-2 review-queue UX pattern for
fuzzy-window pair candidates that Phase 4's `PairTransferCandidates`
listener intentionally skipped: deterministic Layer-1 match landed in
Wave 2 (D-73 + D-75), tolerant-window Layer-2 candidates remain
unpaired and surface in Phase 5's chain-resolver review queue.

The PayPal Reporting API revisit (ING-09) is conditional on a PayPal
Business account upgrade. If/when that trigger fires:
- The Deferred / Future-Revisit section in REQUIREMENTS.md is the
  authoritative entry point; the trigger sentence describes the
  upgrade condition.
- The `noPaypalApiRoute` arch test must be retracted (or scoped
  more narrowly) in the same plan that lands the Reporting API
  adapter, since it would otherwise immediately fail.
- The matching ROADMAP wording for the future API-revisit phase
  inherits the same vocabulary established by this close-out.

## Threat Flags

No new threat surface introduced. The plan's `<threat_model>`
documented two `mitigate` dispositions, both delivered:

- **T-04-W4-01 (tampering — accidental future re-introduction of
  paypal-api code):** `BoundaryArchTest::noPaypalApiRoute`
  grep-scans `routes/` + `Modules/` for the three forbidden literal
  patterns, comments stripped first. Phase 5+ planners see the
  failing arch test if a stray scaffold lands. Mitigation: delivered.
- **T-04-W4-03 (tampering — doc-only changes silently flipping a
  phase requirement's status):** the traceability table update is
  verified by both the `git diff` review pre-commit AND the
  Pending -> Deferred status transition being a visible diff line
  rather than an in-place edit of the requirement entry. Mitigation:
  delivered.

The third entry (T-04-W4-02, info disclosure via doc surface)
remains `accept`: ROADMAP / REQUIREMENTS are checked-in plain text
with no PII or operational secrets.

---
*Phase: 04-paypal-ingestion-transfer-detection*
*Completed: 2026-05-16*
