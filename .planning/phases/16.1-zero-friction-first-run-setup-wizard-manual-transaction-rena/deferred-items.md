# Phase 16.1 — Deferred items (out of scope)

These items were discovered during plan execution but do NOT belong to
the current plan's scope. They are tracked here so a future cleanup
plan can address them.

## Pre-existing `diederik` literal in Onboarding blades

Discovered during plan 16.1-05 close-out arch checks. The
`noDiederikLiteralsInModuleSurface` (or similar) arch invariant fails
on three pre-existing files:

- `Modules/Onboarding/Resources/views/livewire/setup-wizard.blade.php`
  (line 26 — `<span class="wiz-brand-name">diederik</span>`)
- `Modules/Onboarding/Resources/views/livewire/steps/done-step.blade.php`
- `Modules/Onboarding/Resources/views/livewire/steps/welcome-step.blade.php`

These were introduced by plan 16.1-03a + 16.1-03b commits (`81c37b9`,
`8f1f1ce`) and are unrelated to plan 16.1-05's corpus + suggest-modal
work. Plan 16.1-05 cannot land the rename without potentially
re-opening UI-SPEC questions (the brand surface vs the user-facing
copy split is a separate decision).

Suggested follow-up: a small `chore` plan that flips every remaining
`diederik` literal in Modules/Onboarding views to `beatrax`, mirroring
the Phase 16 rename cohort.

## Pre-existing full-suite test failures observed during plan 16.1-07 close-out

Running `composer test --parallel` for the plan 07 close-out gate
surfaced 10 pre-existing failures unrelated to plan 07's aliases
work. Same failures reproduce against the unmodified `main` branch
(verified by checking out the parent commit and re-running) — they
were not introduced by plan 07. Documented here so a follow-up plan
can address them as a cohort.

| Test | Module | Apparent cause |
| ---- | ------ | -------------- |
| `Modules\EmailScan\tests\Integration\GraphCursorTest` | EmailScan | RuntimeException |
| `Modules\EmailScan\tests\Integration\ConcurrentBackfillTest` | EmailScan | ErrorException |
| `Modules\EmailScan\tests\Integration\BackfillGmailTest` | EmailScan | RuntimeException |
| `Modules\EmailScan\tests\Integration\EmlOrphanCleanupTest` | EmailScan | filesystem assertion |
| `Modules\EmailScan\tests\Integration\BackfillGraphTest` | EmailScan | network/filesystem |
| `Modules\EmailScan\tests\Integration\DiscoveryScanNoEmlBlobsTest` | EmailScan | filesystem |
| `Modules\DevMode\tests\Feature\ArtisanStreamReconnectTest` (×2) | DevMode | stream reconnect |
| `Modules\DevMode\tests\Feature\CommandSpawnerTest` | DevMode | process spawn |
| `Modules\Receipts\tests\Feature\Phase7MigrationsTest > rejects an unknown receipt_conflict_resolution value` | Receipts | QueryException not thrown (SQLite trigger drift) |
| `Modules\Core\tests\Unit\DoctorProbesTest > PhpVersionProbe report contains the running PHP version` | Core | environment-specific |
| `Tests\Unit\PhpStanBoundaryRuleTest > emits a BoundaryRule error` | tooling | static-analysis fixture |
| `Tests\Unit\PhpStanBoundaryRuleTest > passes against empty module` | tooling | static-analysis fixture |
| `Tests\Contracts\BoundaryArchTest > does not allow the literal …` | tooling | architecture-test fixture |

Plan 07's own tests stay green:

- `Modules/Import/tests/Unit/LongestCommonPrefixTest` — 11/11
- `Modules/Import/tests/Snapshot/AliasYamlRoundTripTest` — 3/3
- `Modules/Import/tests/Feature/AliasesSettingsPageTest` — 8/8
- `Modules/Import/tests/Feature/AliasMatchPreviewQueryTest` — 6/6
- `Modules/Import/tests/Feature/BulkMergeAliasesTest` — 5/5
- `Modules/Import/tests/Feature/ImportAliasesYamlTest` — 5/5
- Full Modules/Import/tests/ suite: 271 passed.

`composer format:check` (Pint) passes; `composer analyse` (Larastan L10
strict) passes when run with `--memory-limit=2G` (the 128M default on
the worker host trips the autoload bootstrap; the planner's MUST_HAVE
"phpstan green" is satisfied with the bumped limit).
