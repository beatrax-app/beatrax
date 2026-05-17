# Phase 09 — Deferred Items

Out-of-scope discoveries logged here. The orchestrator picks these up after the
phase merges.

## Pre-existing infrastructure failures (NOT caused by Plan 09-02)

### Vite manifest missing on parallel-worktree agents

**Surfaced during:** Plan 09-02 final cross-module regression check.

**Symptom:** 14 of 188 `Modules/Recurring/tests/Feature/*` test cases return
HTTP 500 with `Vite manifest not found at: public/build/manifest.json (View:
resources/views/layouts/app.blade.php)`. Affects `RecurringPageTest`,
`RecurringReviewPageTest`, `RecurringSeriesDetailPageTest`.

**Verified pre-existing:** Same failure reproduces against `HEAD~3` (the Wave 0
merge commit `6c6c7ce`) with Plan 09-02 work removed — so the cause is the
worktree bootstrap, not Plan 09-02.

**Root cause:** The Claude Code parallel-worktree spawns do not build the Vite
asset pipeline (no `npm install && npm run build`); the manifest file does not
exist on disk. The Recurring Feature tests render Blade layouts that embed
`@vite(...)` directives which the test environment expects to resolve via the
manifest.

**Scope:** Out of Plan 09-02 scope. Plan 09-02 touches schema, models, DTOs,
and the state machine — none of these reach the view layer. Same-test
behaviour at `HEAD~3` confirms the failure is independent of this plan's
changes.

**Suggested fix (later plan):** Either ship a checked-in stub manifest for the
test environment, or have the test bootstrap detect a missing manifest and
short-circuit `@vite` to a no-op. Belongs in a dedicated infrastructure plan,
not Plan 09-02.
