---
status: complete
phase: 260527-o5b-restore-import-internal-boundary-by-relo
plan: 01
date: 2026-05-27
commit: 8f9877b
files_changed: 6
gates_passed: 5
---

# 260527-o5b: Restore Import\Internal Boundary by Relocating PreviewSeedHelper

## One-liner

Moved `PreviewSeedHelper` from `Modules/Onboarding/tests/Support/` into `Modules/Import/tests/Support/` so its `use Modules\Import\Internal\Pipeline\PreviewCache;` import becomes an intra-module reference, restoring the `Modules\Import\Internal is only used inside Modules\Import` BoundaryArchTest invariant.

Structural fix, no allow-list carve-out — Import\Internal stays strict.

## Six-file diff

| Operation | Path |
|-----------|------|
| Added     | `Modules/Import/tests/Support/PreviewSeedHelper.php` (namespace `Modules\Import\Tests\Support`) |
| Deleted   | `Modules/Onboarding/tests/Support/PreviewSeedHelper.php` |
| Modified  | `Modules/Onboarding/tests/Feature/ConsolidatedPreviewLoadTest.php` — `use` switched to `Modules\Import\Tests\Support\PreviewSeedHelper` |
| Modified  | `Modules/Onboarding/tests/Feature/FirstImportStepCommitEverythingTest.php` — `use` switched + Pint-reordered imports |
| Modified  | `Modules/Onboarding/tests/Feature/FirstImportStepCommitRollbackTest.php` — `use` switched + Pint-reordered imports |
| Modified  | `Modules/Onboarding/tests/Feature/FirstImportStepStaleIdFilterTest.php` — `use` switched + Pint-reordered imports |

Git recorded the helper change as a rename (`Modules/{Onboarding => Import}/tests/Support/PreviewSeedHelper.php`, 98% similarity). Helper body is byte-identical except for the namespace declaration on line 5.

## Boundary test name that flipped RED → GREEN

`Modules\Import\Internal is only used inside Modules\Import` (Tests\Contracts\BoundaryArchTest, line 21–23). Now passes (`✓ … 0.14s`) alongside every other BoundaryArchTest invariant.

## Five-gate outcomes

| # | Gate | Exit | Result |
|---|------|------|--------|
| 1 | `composer dump-autoload` | 0 | **PASS** — 82,405 classes regenerated. |
| 2 | `vendor/bin/pest --filter BoundaryArch` | 0 | **PASS** — 51 passed (85 assertions, 7.36s). The previously failing `Modules\Import\Internal is only used inside Modules\Import` assertion is green. |
| 3 | `vendor/bin/pest <4 feature suites>` | 0 | **PASS** — 5 passed (22 assertions, 0.50s). Every previously passing feature test still passes under the new helper namespace. |
| 4 | `vendor/bin/pint --test <5 files>` | 0 | **PASS** — `{"tool":"pint","result":"passed"}`. Note: initial `--test` run reported 4 files needing `ordered_imports` (the new `use` statement was out of alpha order relative to the surrounding `Modules\…` imports); ran `pint` without `--test` to apply, then re-ran `--test` to confirm clean. |
| 5 | `vendor/bin/phpstan analyse --memory-limit=1G <5 files>` | 0 (effective) | **PASS (effective)** — see deviation note below. |

### Phpstan gate deviation (Rule 3 — blocking gate-shape mismatch)

The plan-specified Gate 5 file list targets `Modules/Import/tests/…` and `Modules/Onboarding/tests/…` paths. The project's `phpstan.neon` `excludePaths.analyse` explicitly excludes `Modules/*/tests/*` and `tests/*` (lines 18 + 21 of `phpstan.neon`), so the per-file invocation aborts with `[ERROR] No files found to analyse` and exit code 1.

This is a structural mismatch between the plan and the actual phpstan configuration, not a code issue. To preserve the substantive invariant the gate exists for (no static-analysis regressions caused by the change), I ran the full-tree analysis instead:

```
vendor/bin/phpstan analyse --memory-limit=1G
→ 738/738 [▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓] 100%
→ [OK] No errors
→ exit 0
```

Larastan L10 strict reports zero errors across the entire production tree, so the helper relocation has not introduced any static-analysis problem reachable from production code. Since tests are by design out of phpstan's scope on this project, the per-file gate as written was infeasible; the full-tree gate is the most rigorous equivalent the existing configuration supports.

## Commit

```
8f9877b fix(arch): relocate PreviewSeedHelper into Import module to restore Internal boundary
 5 files changed, 5 insertions(+), 5 deletions(-)
 rename Modules/{Onboarding => Import}/tests/Support/PreviewSeedHelper.php (98%)
```

(Git collapses the new + deleted PreviewSeedHelper pair into a single rename in its file-count, hence 5 files reported in the commit summary; the logical changeset is 6 paths — 1 new, 1 deleted, 4 modified.)

## Self-Check: PASSED

- `Modules/Import/tests/Support/PreviewSeedHelper.php` present, namespace `Modules\Import\Tests\Support`, `use Modules\Import\Internal\Pipeline\PreviewCache;` retained (intra-module).
- `Modules/Onboarding/tests/Support/PreviewSeedHelper.php` removed.
- Zero remaining references to `Modules\Onboarding\Tests\Support\PreviewSeedHelper` across `Modules/` and `tests/`.
- All 4 feature tests `use Modules\Import\Tests\Support\PreviewSeedHelper;` once each.
- `composer.json` unmodified.
- `tests/Contracts/BoundaryArchTest.php` unmodified.
- `Modules/Import/Internal/Pipeline/PreviewCache.php` unmodified.
- `FirstImportStep.php` / `first-import-step.blade.php` unmodified (out-of-scope WIP not touched).
- Commit `8f9877b` exists in `git log` on `main`.
