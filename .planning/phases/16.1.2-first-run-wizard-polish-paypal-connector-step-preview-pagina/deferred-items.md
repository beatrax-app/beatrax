# Phase 16.1.2 — Deferred Items

Out-of-scope discoveries surfaced during execution. Each item is environment
or pre-existing and **not** caused by any plan in this phase.

## Pre-existing test failures

### `ViteManifestNotFoundException` across HTTP-render tests (5 tests in Import Feature suite)

Failing tests (all hit `GET /imports/*` or `/settings/aliases` and render `resources/views/layouts/app.blade.php`, which calls `@vite(...)`):

1. `AliasesSettingsPageTest::it renders /settings/aliases for an authenticated user with the first-class layout`
2. `PreviewWizardTest::it renders the canonical results summary on the results page`
3. `UploadWizardPaypalTest::it renders the PayPal issuer option on the wizard page`
4. `UploadWizardTest::it renders the calm upload form on GET /imports/new`
5. `UploadWizardTest::it renders the two-step picker on the upload page`

- **Failure:** `Illuminate\Foundation\ViteManifestNotFoundException: Vite manifest not found at: public/build/manifest.json`
- **Cause:** The worktree has no built Vite assets. The layout's `@vite(...)` directive requires `public/build/manifest.json`. Reproducible against the base commit `3931eff` (last PreviewWizard code touch was `7c11f90` in 16.1.1-06, well before this phase).
- **Scope:** Pre-existing environment failure, unrelated to Plan 01's `EnsurePaypalAccountAction` extraction.
- **Recommended fix:** Run `npm run build` before the test suite, or rewrite the affected tests to use `Livewire::test(...)` rather than a full HTTP GET.

## Pre-existing GSD-style references in source

### `PreviewWizard.php` PHPDoc — `D-103 / D-105` and `Issue #1 + #8`

- **File:** `Modules/Import/Internal/Http/Livewire/PreviewWizard.php` (lines ~80-94, 130-134, 148)
- **Pattern:** PHPDoc on `$chainResolutionStatus` and `refreshChainResolutionStatus()` references `D-103 / D-105` and `Issue #1 + #8`.
- **Scope:** Pre-existing, untouched by Phase 16.1.2 Plan 01. The plan's `<action>` explicitly forbids editing other PreviewWizard methods.
- **Recommended fix:** Strip the GSD-style decision IDs from the docblock as a docs-only cleanup, per the project memory `feedback_codebase_gsd_agnostic`.
