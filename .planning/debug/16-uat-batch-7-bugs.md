---
slug: 16-uat-batch-7-bugs
status: open
trigger: User reported 7 UAT issues in one batch during Phase 16 UAT (post-batch-5)
goal: find_and_fix
tdd_mode: false
created: 2026-05-25
---

# Debug: Phase 16 UAT — 7-issue batch

## Symptoms

Seven issues reported by the user during Phase 16 UAT. Mix of bugs, UI
polish, and one small feature. To be worked through with atomic commits
per issue (or per batched touch-point). All three gates (Pint, Larastan
L10 strict, Pest) must pass before each commit.

### Issue 1 — CSV → XML re-import dedup is wrong (BUG)

Importing a date range first as CSV, then re-importing the same range
as XML (CAMT.053), shows many rows as "enriched" because the importer
tries to UPDATE `source_ref` instead of recognizing the row is the same
transaction and dropping it. Likely a dedup-key mismatch: CSV path
produces one `source_ref` shape, CAMT path a different one, so the
secondary importer can't match.

Investigation:
- Locate the dedup-match stage (likely under
  `Modules/Import/Internal/Pipeline/`).
- Print both source_ref values for the same logical transaction.
- Decide the right canonical match — probably matching on
  `(account_iban, booking_date, amount_minor, counterparty_ref)`
  independent of source_ref, with source_ref upgraded but not
  re-keying the row.

Fix: drop the row as duplicate when the canonical fingerprint matches,
even if source_ref differs.

### Issue 2 — PayPal activity CSV preview errors silently (BUG)

Selecting the PayPal Activity CSV throws an error on the import preview
screen. No entry in the dev audit log. Need a stack trace — check
`storage/logs/laravel.log`, Telescope if enabled, browser console, the
Livewire component's `$errors`. PayPal CSV parser path probably under
`Modules/Import/Internal/Parsers/PayPal*` or similar; also check the
dispatcher that picks a parser by file shape.

Fix:
- The actual parse failure.
- Make the dev audit logger capture it so future failures aren't silent.

### Issue 3 — System info overview boxes misaligned (UI)

On the system info / overview page, the info-box contents don't align
(uneven inner padding, missing `items-start`/`items-stretch`, or
`h-full` not applied on the box body). Find the page (route likely
`/system-info` or under `/dev/`) and fix flex/grid alignment.

### Issue 4 — ICS PDF upload "files.0 failed to upload" (BUG, NativePHP-specific)

Exception captured:
```
Illuminate\Validation\ValidationException: The files.0 failed to upload.
at vendor/laravel/framework/src/Illuminate/Support/helpers.php:423
```
userId=1. Path resolves to the bundled NativePHP app
(`nativephp/electron/dist/mac-arm64/beatrax.app/Contents/Resources/build/...`).

Likely causes, in order of probability:
- (a) `upload_max_filesize` / `post_max_size` in the NativePHP-bundled
  php.ini is lower than the PDF size. Check what NativePHP ships and
  override if needed (NativePHP exposes ini overrides via
  `config/nativephp.php` or a runtime hook).
- (b) Livewire temp upload directory isn't writable inside the .app
  sandbox. Check `config/livewire.php`
  `temporary_file_upload.directory`; verify it resolves to a writable
  runtime path (the app data folder under
  `~/Library/Application Support/beatrax/`, NOT inside the read-only
  .app bundle).
- (c) The upload component's `accept` / mime / rules array rejects
  PDFs (less likely since the error is "failed to upload" not
  "validation failed on file").

Reproduce locally first if possible (in `php artisan serve` mode) —
if it works there but fails in the bundle, the cause is bundle-specific
(a or b). Fix at the right layer.

### Issue 5 — Error logs page: truncate-all + per-row copy (FEATURE)

Add a "Clear all" / "Truncate" button to the error logs page that wipes
the existing entries, with a confirm dialog. Also add a "Copy"
affordance per row that copies the full error output (message +
stack/details) to the clipboard. Use Flux UI components where possible.
Find the page under `Modules/Desktop/Internal/Http/Livewire/` or
similar. Add Pest feature tests for the truncate action.

### Issue 6 — Email import Connect buttons are no-ops (BUG)

On the email import / inbox configuration pages, clicking "Connect"
does nothing. Could be missing `wire:click`, missing handler method,
missing route binding, or a JS error breaking the Livewire mount.
Relevant pages: inbox / email config under the EmailScan module (or
similar). Trace button → handler → action → expected redirect (probably
to OAuth consent URL) and fix the broken link.

### Issue 7 — Sticky bottom bar (UI)

Developer bar / user bar / signout area at the bottom of the layout
currently scrolls off when viewport content is long. Should stay pinned
to viewport bottom. Likely fix: in the app layout (look in
`resources/views/layouts/` and any Flux nav primitives), wrap the
bottom bar with sticky/fixed positioning class, and ensure the
scrollable content area has the right overflow / min-height container.
Test in both modes (short page, long page).

## Constraints recap (from CLAUDE.md / memory)

- Constructor DI only — no facade calls / global helpers; Eloquent
  models direct OK.
- No `.planning/` / PLAN.md / RESEARCH.md references in code, PHPDocs,
  or comments.
- PHPDocs reflect what code does now (no "I changed this because X"
  comments).
- Fix every severity: BLOCKER + WARNING + INFO together.
- Commit-message convention: `fix(16): …` / `feat(16): …`.
- Don't rebuild the NativePHP app — user will do that.

## Triage order (suggested, may be re-batched)

1. Issue 3 (CSS)
2. Issue 7 (CSS/layout)
3. Issue 6 (small, high-impact)
4. Issue 5 (feature, well-scoped)
5. Issues 1, 2, 4 (import bugs needing deeper investigation)

Batch by area if cleaner.

## Current Focus

- hypothesis: triage suggests starting with quick UI wins (3, 7), then
  Issue 6 connect-button trace, then feature 5, then deeper import
  bugs (1, 2, 4)
- next_action: begin investigation pass — survey repo layout for the
  files implicated by each issue, confirm triage batching, then start
  fixing in atomic commits

## Evidence

(populated by investigator)

## Resolution

(populated when complete)
