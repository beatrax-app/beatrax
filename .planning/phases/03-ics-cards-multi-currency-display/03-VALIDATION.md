---
phase: 3
slug: ics-cards-multi-currency-display
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-05-13
---

# Phase 3 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest 3.x (on PHPUnit 11) |
| **Config file** | `phpunit.xml` |
| **Quick run command** | `php artisan test --parallel --stop-on-failure` |
| **Full suite command** | `php artisan test --parallel && composer larastan && composer pint:test` |
| **Estimated runtime** | ~{TBD — to be filled by planner from Wave 0 baseline} seconds |

---

## Sampling Rate

- **After every task commit:** Run `php artisan test --parallel --stop-on-failure`
- **After every plan wave:** Run full suite (`test --parallel && larastan && pint:test`)
- **Before `/gsd-verify-work`:** Full suite must be green at Larastan level 10 strict + Pint clean
- **Max feedback latency:** {TBD} seconds

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| {planner fills} | | | | | | | | | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `Modules/Ingestion/tests/fixtures/ics/` — anonymised ICS CSV fixture (raw user file → redacted card numbers / cardholder names; preserve dates / amounts / currencies / merchants verbatim per D-32)
- [ ] `Modules/Ingestion/tests/Unit/Adapters/Ics/IcsCsvAdapterTest.php` — stubs for ING-04 (parser unit tests including FX-row shape per D-35)
- [ ] `Modules/Ingestion/tests/Snapshots/` — snapshot stubs for canonical DTO stream (via `spatie/pest-plugin-snapshots`)
- [ ] `Modules/Import/tests/Unit/Pipeline/Stages/NormalizeStageTest.php` — extend for D-42 `settled = native` substitution branch (LED-03)
- [ ] `Modules/Ledger/tests/Unit/Services/ThisPeriodAtAGlanceQueryTest.php` — extend for D-46 GROUP-BY-currency mode (MC-02)
- [ ] `Modules/Core/tests/Feature/SettingsPageTest.php` — Livewire feature test for `/settings` round-trip (MC-02, UI-06)
- [ ] `Modules/Import/tests/Feature/UploadWizardTest.php` — extend for two-step issuer→format picker (D-33), regenerate snapshots
- [ ] `Modules/Ledger/tests/Feature/TransactionListCurrencyToggleTest.php` — `?currency=` URL override + per-page toggle (MC-02, UI-06)

*If none: "Existing infrastructure covers all phase requirements."*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Calm aesthetic of /settings page matches Linear/Notion feel | UI-05 (cross-cut) | Subjective design review — no automated check for "calm" | Open `/settings` on Herd at `https://diederik.test/settings`; confirm spacing, single accent color, no dense form chrome |
| Two-step wizard picker UX feels natural with PayPal/Google Play headroom | D-33 | Forward-looking UX judgment | Walk through `Upload Statement` wizard; confirm issuer dropdown reads cleanly and the format dropdown only shows the leaf for the chosen issuer |
| Foreign-currency row visual balance in original-currency view | UI-06 | Subjective two-line stack readability | Import the Wave 0 ICS fixture, switch list to "Original", inspect a USD row — primary `$X` line + muted `€Y` line should not visually crowd |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < {TBD}s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
