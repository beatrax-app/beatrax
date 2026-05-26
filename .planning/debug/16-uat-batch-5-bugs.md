---
slug: 16-uat-batch-5-bugs
status: resolved
trigger: User reported 5 bugs in one batch during Phase 16 UAT
goal: find_and_fix
tdd_mode: false
created: 2026-05-24
resolved: 2026-05-25
---

# Debug: Phase 16 UAT — 5-bug batch

## Symptoms

Five bugs reported by the user during Phase 16 UAT.

## Resolution status

### Bug 4 + Bug 5 — FIXED (commit `ea9f885`)
Tailwind v4 wasn't scanning Flux's vendor stubs, so dark-mode utility
classes never reached the built CSS — buttons had no visible bg/text
in dark mode and modals rendered as blank white overlays. Added one
`@source` directive at the top of `resources/css/app.css` pointing at
the Flux stubs path. Zinc-utility count in the bundle jumped from 0
to ~50; ArtisanRunnerSafeTier feature tests pass 12/12.

### Bug 3 — FIXED (commit `442f139`)
`PreviewRowDto` carried only `counterpartyName` and `counterpartyIban`;
ASN bank-fee / interest / ATM rows have neither, so the Counterparty
cell rendered "—" even though the joined payment-reference +
description was non-empty. Added `description` to `PreviewRowDto`,
threaded it through `ImportPipeline`, extended the wizard view's
fallback chain to name → IBAN → description → "—". New test
`PreviewWizardDescriptionFallbackTest` locks in the third tier.

### Bug 2 — FIXED (commit `7e37921`)
Per the user's clarification (option (c) variant): rename "Source"
column header to "Funding source" and render the counterparty IBAN
in the cell. The own-account name that used to live there was
removed from the preview by user choice; redundancy with the
Counterparty column's IBAN fallback is accepted. Also dropped the
now-dead `sourceAccountName` field from `PreviewRowDto` and the
`accountNameFor()` cache plumbing inside `ImportPipeline`. New test
`PreviewWizardFundingSourceColumnTest` locks in three invariants:
the new header label, the IBAN cell render, and the em-dash fallback
when the source row carries no counterparty IBAN.

### Bug 1 — FIXED (commit `1aa9135`)
Root cause: single-user installs that pre-date the
`SeedDefaultCategoryTree` listener wiring landed on dev machines
with User id=1 but an empty `categories` table. The `/transactions`
per-row inline category dropdown then rendered only "—" with no real
options (and auto-cat had nothing to assign because there were no
rule→category mappings and no categories at all). The user's
"emdash in the select with nothing else to select" symptom maps
directly onto this empty-categories state.

Fix: `beatrax:install` now always dispatches `UserInstalled` for the
resolved user (whether newly created or pre-existing). Since
`SeedDefaultCategoryTree` (and any future install-time seed listener)
is required to be idempotent, re-running the install command heals
missing seed data without any new artisan command. Updated
`UserInstalled`'s docblock to make the heal contract explicit so
future listeners know up-front they must be idempotent.

The user's live dev database (`database/nativephp.sqlite`) was healed
in-session by running `DB_DATABASE=…/nativephp.sqlite php artisan
beatrax:install`; categories table grew from 0 → 29 rows.

New `re-dispatches UserInstalled on a re-run` test in
`InstallCommandTest` verifies end-to-end behavior: wiping the seeded
categories table and re-running install restores it.

## Gates

All commits passed (per-commit, locally):
- Laravel Pint: clean
- Larastan level 10 strict: 0 errors across all analysed paths
- Pest: targeted module suites all green (Categorization 131/131,
  Import 157/157, Core/InstallCommand 7/7, Ledger + Chains + Recurring
  610/610)

## Evidence

- 2026-05-24: Tailwind v4 vendor-scan diagnosis (Bug 4 + 5).
- 2026-05-24: Description-fallback diagnosis (Bug 3).
- 2026-05-25: Direct inspection of `database/nativephp.sqlite`:
  `SELECT COUNT(*) FROM categories` returned 0 — confirming the
  empty-default-tree state behind Bug 1's symptoms. Post-heal:
  same query returned 29.
- 2026-05-25: Verified `ImportPipeline::preview()` already runs
  `ApplyAutoCategoryStage` against every row; auto-cat WAS firing
  but had nothing to assign because rules and categories were
  missing — confirming the dropdown empties were a symptom of
  the empty seed tree, not a broken pipeline.
- 2026-05-25: `Categorization::InlineCategoryPicker` renders the
  matching `<option value="">—</option>` literal when the
  `CategoryOptionsQuery` returns an empty list — the
  user-reported symptom.
