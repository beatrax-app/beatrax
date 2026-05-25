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
