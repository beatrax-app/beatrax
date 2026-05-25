# Phase 16.1 — Deferred Items

Out-of-scope discoveries surfaced during plan execution. Logged here so a
later plan can close them; per the deviation rules, only issues caused
by the current task's changes get auto-fixed.

## 16.1-03a discoveries

- **`Modules/Community/tests/Unit` directory missing.** `phpunit.xml`
  registers `Modules/Community/tests/Unit` under the `Unit` test suite
  but Plan 01's scaffolding only landed `Modules/Community/tests/Feature/`
  (plus `Pest.php` + `TestCase.php`). Running `composer test` (sequential
  or parallel) fails fast with:

  ```
  In TestSuiteMapper.php line 71:
    Test directory ".../Modules/Community/tests/Unit" not found
  ```

  Fix in a follow-up plan: either create `Modules/Community/tests/Unit/`
  (with at least a `.gitkeep`) or drop the entry from `phpunit.xml`
  until Plan 06 adds the first Community unit test. Plan 03a's
  Onboarding tests are unaffected — they were run directly via
  `vendor/bin/pest Modules/Onboarding/tests/`.

  Closing this is a 1-file change; it stays out of Plan 03a scope to
  preserve the Plan 01 boundary.

- **`Modules/Core/tests/Unit/DoctorProbesTest::PhpVersionProbe` fails on
  PHP < 8.5.** The probe enforces the project-mandated PHP 8.5+ minimum
  and the test environment in this worktree reports `8.4.11`. Pre-existing
  for any developer who hasn't upgraded to PHP 8.5 locally; the doctor
  probe itself is doing exactly what it should (failing loud on a
  too-old runtime). Plan 03a's tests target Modules/Onboarding which is
  PHP-version-agnostic. Resolve when the developer machine moves to 8.5
  — not a code change.

## 16.1-03b discoveries

- **`BoundaryArchTest > does not allow the literal diederik` — pre-existing
  on Plan 03a's blade + test files.** The arch invariant landed in Plan 01
  (commit `bfcfd20`) flags any `diederik` / `Diederik` literal under
  `Modules/`, `tests/`, `resources/`, `config/`. Plan 03a's `welcome-step`
  / `done-step` / `setup-wizard` blades and its `ResumeWizardTest` /
  `ReRunWizardTest` fixtures all carry the UI-SPEC-mandated `diederik`
  literal copy. Plan 03b's Task 1 edits preserved that copy unchanged
  (the edits swapped inline markup for `<x-onboarding::*>` Blade
  components — no new offender introduced).

  Plan 03b's new files (connect-bank, connect-card, connect-email,
  first-import blades) flip every brand reference to `beatrax` per the
  arch invariant.

  Fix in a follow-up `diederik → beatrax` brand-rename plan that
  amends UI-SPEC §"Copywriting Contract" and re-baselines the Onboarding
  blade copy + the two test fixture strings. Out of scope for Plan 03b.

- **`Tests\Unit\PhpStanBoundaryRuleTest` — env-specific phpstan memory
  exhaustion in worker subprocess.** The test spawns a `vendor/bin/phpstan`
  child process via `Symfony\Process` to assert the custom BoundaryRule
  emits the expected error; the subprocess inherits the default
  `memory_limit=128M` and fatals with "Allowed memory size of 134217728
  bytes exhausted" while loading the composer classmap (82k+ classes).
  Reproducible on the worktree, passes on the main repo (different
  Process inheritance). Pre-existing — Plan 03b touches no phpstan
  rule code.

- **`Modules\Receipts\tests\Feature\Phase7MigrationsTest::rejects ...` —
  Receipts module regression.** Unrelated to Plan 03b's Onboarding
  scope. Pre-existing on `main`.
