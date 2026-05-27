---
phase: 17
slug: ci-cd-pipeline-code-signing
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-05-27
---

# Phase 17 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest 3.x (built on PHPUnit 11) |
| **Config file** | `phpunit.xml` |
| **Quick run command** | `vendor/bin/pest --parallel` |
| **Full suite command** | `composer test` (Larastan L10 strict + Pint + Pest) |
| **Estimated runtime** | ~120 seconds (Pest) + ~60s Larastan + ~10s Pint |

---

## Sampling Rate

- **After every task commit:** Run `vendor/bin/pest --filter <relevant>` (≤30s scoped)
- **After every plan wave:** Run `composer test` (full quality gate)
- **Before `/gsd:verify-work`:** Full suite + Larastan L10 strict must be green
- **Max feedback latency:** ~180 seconds

---

## Per-Task Verification Map

> The planner fills this table after PLAN.md files are generated. Each task references its REQ-ID + Test Type + Automated Command.

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| TBD-by-planner | — | — | — | — | — | — | — | — | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `Modules/Counterparties/tests/Unit/CounterpartyResolverTest.php` — stubs for the 7-step resolution chain (A-04)
- [ ] `Modules/Counterparties/tests/Feature/CounterpartiesIndexPageTest.php` — Livewire page + type-filter chips
- [ ] `Modules/Counterparties/tests/Arch/CounterpartiesBoundaryTest.php` — module boundary arch invariant
- [ ] `tests/Arch/NoGsdLeakageTest.php` — REL-05 / D-27 arch invariant
- [ ] `tests/Arch/NoSecretsInLivewireSnapshotTest.php` — REL-07 / D-29 arch invariant
- [ ] `tests/Feature/HealthEndpointTest.php` — CI-05 smoke target (`/health` JSON contract)
- [ ] `tests/Feature/Bootstrap/AppKeyRegenerationTest.php` — CI-06 first-launch sentinel
- [ ] `tests/Feature/AutoUpdate/Ed25519ManifestVerificationTest.php` — A-06 / D-20 load-bearing integrity test (tamper-proof)
- [ ] `tests/Feature/AutoUpdate/Sha512BinaryVerificationTest.php` — A-06 binary integrity
- [ ] CI workflow YAML lint script (`yamllint .github/workflows/*.yml`) wired into Pest as a process test

*Wave 0 also includes ensuring CI workflow files are syntactically valid before the first release-pipeline run.*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Push `v0.1.0-rc.1` → release.yml runs end-to-end on macOS-14 + Windows-2025 + Ubuntu-24.04 runners | CI-02 | Requires real GitHub runner network; cannot run on local CI | `git tag v0.1.0-rc.1 && git push --tags`; verify all three platform artifacts publish + smoke-test passes per platform |
| First-launch on a fresh macOS / Windows / Linux box completes Gatekeeper / SmartScreen bypass per README copy | A-05 / Section K | Real-user OS-security UX | Walk a non-technical helper through the README install section; record where they stall |
| GitHub repo security walkthrough — branch protection, secret scanning, Dependabot, CodeQL, signed commits enforced on `main` | D-49 / D-50 | Interactive GitHub UI configuration, captured to `.docs/cicd/branch-protection.md` | Open repo Settings; follow walkthrough items 1–14; capture final state |
| `.planning/` git-history purge executed against `nightworksio/beatrax` (private) origin | D-35 / D-36 / A-07 | DESTRUCTIVE — requires explicit user confirmation in-session | Fresh clone → `git filter-repo --path .planning --invert-paths` → verify no `.planning/` blobs in history → add `.planning/` to `.gitignore` → force-push (still safe — repo private) |
| Repo visibility flip private → public after Plan 17-19 smoke passes | D-50 baseline | Manual GitHub Settings → Danger Zone action | Settings → Change visibility → confirm; verify clone via incognito; post welcome to Discussions |
| v1.0.0 graduation tag push (Plan 17-20) | D-18 | User-triggered explicit ship moment, no automation | Final tag `git tag v1.0.0 && git push --tags`; promote DRAFT → published release |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 180s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
