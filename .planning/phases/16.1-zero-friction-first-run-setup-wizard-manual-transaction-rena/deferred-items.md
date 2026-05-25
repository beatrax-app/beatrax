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
