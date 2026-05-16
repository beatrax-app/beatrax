---
phase: 5
slug: chain-resolution-paypal-funding-ics-bulk-ideal-decomposition
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-05-16
---

# Phase 5 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.
> Source: `05-RESEARCH.md` § "Validation Architecture" + the concrete plans `05-{NN}-PLAN.md` (filled in once planner writes plans).

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest 4.0 (PHPUnit 11 engine), `pest-plugin-laravel ^4.0`, `pest-plugin-arch ^4.0`, `pest-plugin-snapshots ^2.0` |
| **Config file** | `phpunit.xml` (project root); module-local `Modules/Chains/tests/Pest.php` (new in Phase 5, matches Phase 1/2/3/4 convention) |
| **Quick run command** | `vendor/bin/pest --filter "Chains\|PairLookup\|ResolveChainLinksJob\|CardStatementStateMachine\|IcsSettlementResolver\|PaypalFundingResolver\|ChainReviewQueue\|ChainDrawer\|NextIcsSettlement"` |
| **Full suite command** | `composer test` (alias for `pest --parallel`) |
| **Static-analysis gate** | `composer analyse` (Larastan level 10 strict — zero new errors) |
| **Style gate** | `composer format:check` (Laravel Pint — clean) |
| **Estimated runtime** | quick filter ~10s · full suite ~60s (Phase 4 baseline +~15s for Phase 5 surface) |

---

## Sampling Rate

- **After every task commit:** Run `{quick run command}` filtered to the touched module/test class
- **After every plan wave:** Run `{full suite command}` (full Pest + analyse + format:check)
- **Before `/gsd-verify-work`:** Full suite must be green; BoundaryArchTest extensions (D-84 / D-95 / no-`DB::*`-in-Chains) must pass
- **Max feedback latency:** 60 seconds

---

## Per-Task Verification Map

> Filled in by the planner when `05-{NN}-PLAN.md` files are written. Each task's `<automated>` block contributes one row here. Coverage targets (from `05-RESEARCH.md` § "Validation Architecture" — 35-test matrix):
>
> - **Contract tests** — Public surface invariants (`ChainLinkQuery`, `ConfirmChainLink`, `RejectChainLink`, `CardStatementQuery`, `PairLookup` promotion) + cross-module idempotency
> - **Unit tests** — `IcsSettlementResolver` tolerance arms (clean / over / under / refund-after-close), `PaypalFundingResolver` deterministic + fuzzy paths + D-106 General-Withdrawal hand-off, `CardStatementStateMachine` lifecycle (`open` → `partially_settled` → `settled` / `overpaid`), auto-promotion signature counter (D-87 / D-88), three-tier confidence chip mapping (D-91)
> - **Integration tests** — `ResolveChainLinksJob` Horizon dispatch (per-user uniqueness via `ShouldBeUniqueUntilProcessing`, retry/backoff D-103), `ConfirmImport` post-commit dispatch, wizard `wire:poll` resolution status surface, failed-job toast
> - **Property-based tests** — bulk-settle SUM-within-tolerance invariants (±€5 / ±2% / ±10-day), credit-carry preservation across statements (D-96 / D-98)
> - **Fixture-anchored tests** — synthesised cross-source scenario trio (clean / overpaid / underpaid) per D-107 / D-108
> - **Arch / Boundary tests** — D-84 (no `Modules/Chains/` calls `Transaction::update()` / raw update on transactions), D-95 (only `CardStatementStateMachine` mutates `card_statements.state`), no `DB::*` facade in `Modules/Chains/`, DI-only no-facade enforcement
> - **Snapshot tests** — chain drawer (Flux flyout) for full / empty / single-leg / N-leg / fan-out-paginated states; review queue for empty / full / mixed-confidence states; "Next ICS settlement" tile for open / settled / overpaid states

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| _(filled by planner)_ | | | | | | | | | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `Modules/Chains/composer.json` + `ChainsServiceProvider` registered in `bootstrap/providers.php`
- [ ] `Modules/Chains/tests/Pest.php` — module-local Pest bootstrap (mirrors Phase 1/2/3/4)
- [ ] `Modules/Chains/tests/fixtures/scenario-1/` — synthesised ASN CAMT.053 + ICS PDF + PayPal CSV trio per D-107
- [ ] `scripts/synthesise_phase5_scenario.php` — fixture synthesis script per D-108
- [ ] `laravel/horizon ^5.x` + `predis/predis ^3.x` composer require + `config/horizon.php` published
- [ ] Redis Docker container reachable on `127.0.0.1:6379` (loopback-bound per RESEARCH.md Pitfall 8)
- [ ] `Modules/Transfers/Public/Services/PairLookup.php` promotion + smoke contract test
- [ ] `tests/Architecture/BoundaryArchTest.php` extended with D-84 / D-95 / no-`DB::*`-in-Chains rules
- [ ] PROJECT.md amendment (Horizon + Redis flip from "What NOT to Use" → recommended; Docker-for-Redis carve-out)
- [ ] README setup section for Docker Redis + manual `php artisan horizon` second terminal

*Synthesised fixtures cover three reconciliation arms (clean / overpaid / underpaid) so all `IcsSettlementResolver` tolerance branches exercise against committed data.*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Chain drawer Flux flyout visual polish | UI-02 | First Flux drawer in project — visual baseline locked by snapshot tests, but keyboard ergonomics (Esc, click-outside, focus trap) require browser verification | Open `/transactions/{id}` → "View chain" → verify Esc closes, click-outside closes, Tab traps focus inside drawer; verify scroll behavior matches D-92 (full-height no outer scroll) |
| `/horizon` dashboard accessibility | (operational) | Loopback-bound; live Horizon dashboard requires running `php artisan horizon` in a second terminal | Start Redis container, run `php artisan horizon`, visit `http://diederik.test/horizon`, verify supervisor is online and `ResolveChainLinksJob` shows in the queue |
| End-to-end chain resolution against user's real data | CHN-01..CHN-07 | Synthesised fixtures cover algorithmic correctness, but real ASN+ICS+PayPal export alignment is a follow-up smoke test once real exports line up | Import user's most recent ASN CAMT.053 + ICS PDF + PayPal CSV in chronological order; verify dashboard "Next ICS settlement" tile populates; drill into a Netflix-via-PayPal row and verify chain tree resolves to ASN/ICS account |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references (composer/Docker/Horizon/fixtures/PROJECT.md/README)
- [ ] No watch-mode flags
- [ ] Feedback latency < 60s
- [ ] `nyquist_compliant: true` set in frontmatter after planner writes plans + per-task rows

**Approval:** pending (set after planner completes)
