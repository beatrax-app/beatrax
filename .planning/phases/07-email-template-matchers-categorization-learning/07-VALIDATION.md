---
phase: 07
slug: email-template-matchers-categorization-learning
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-05-17
---

# Phase 07 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.
> Source axes: see `07-RESEARCH.md` § Validation Architecture.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest 4.x (built on PHPUnit 11), Larastan max, Pint |
| **Config file** | `phpunit.xml` + `pest.config.php` + `phpstan.neon` + `pint.json` |
| **Quick run command** | `./vendor/bin/pest --filter=Receipts --filter=Categorization --parallel` |
| **Full suite command** | `./vendor/bin/pest --parallel && ./vendor/bin/phpstan analyse --memory-limit=2G && ./vendor/bin/pint --test` |
| **Estimated runtime** | quick ~8s · full ~45s · static analysis ~25s |

Per the project's CI gates (`.github/workflows/ci.yml`), the full suite must pass on every PR. Wave 0 of Phase 7 registers the new `Modules/Receipts/` test paths in `phpunit.xml` and `pest.config.php`.

---

## Sampling Rate

- **After every task commit:** Run quick command (filtered to changed modules — Receipts and/or Categorization).
- **After every plan wave:** Run full suite.
- **Before `/gsd:verify-work`:** Full suite green + Larastan max green + Pint green.
- **Max feedback latency:** 60 seconds (quick command must stay under 30s including boot).

No watch-mode flags — Pest's `--parallel` is the throughput lever; flakiness from filesystem race conditions surfaces on the second run.

---

## Validation Axes (Nyquist — 8 dimensions, mapped from RESEARCH.md)

1. **Header-shape parser correctness** — per-sender matcher `canHandle()` returns true/false correctly across template generations.
2. **Body-decoding correctness** — charset (UTF-8, Windows-1252, ISO-8859-1), quoted-printable, base64, multipart/alternative ordering.
3. **DTO equivalence (cross-format fingerprint parity)** — matcher output ≡ CSV-derived `SourceTransactionDto` at `FingerprintComposer v3` level.
4. **Conflict-resolution lifecycle** — `receipt_conflict_resolution = unset → set → applied silently thereafter` (D-707).
5. **Rule precedence + specificity scoring** — D-711 algorithm correctness across (rule equals · rule starts_with · rule contains · merchant_memory) tuples + rule-beats-memory tiebreaker at equal score.
6. **Idempotency under re-drop / re-scan** — same `Message-ID` (or sha256 synthetic per D-705a) twice = no-op; `.mbox` re-import dedup row-by-row.
7. **Pipeline integration boundary** — `ApplyAutoCategoryStage` placement after `NormalizeStage`, before `FingerprintStage`, side-effect-free on stage failure.
8. **Boundary invariants (arch tests)** — `BoundaryArchTest::noEmailFetchFromReceipts`, no-facade-in-Receipts, no-helper (`auth()`, `request()`, `config()`) calls, no transaction writes from EmailScan (carry-forward from D-132).

---

## Per-Task Verification Map (skeleton — planner fills in Task IDs during PLAN.md emission)

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 07-01-* | 01 | 0 | EML-05/07, CAT-02/04 | — | N/A (scaffolding) | unit/arch | `./vendor/bin/pest --filter=BoundaryArchTest`, `./vendor/bin/pest --filter=ReceiptsModuleSkeleton` | ❌ W0 | ⬜ pending |
| 07-02-* | 02 | 1 | EML-05 | T-07-01, T-07-04 | matcher parses anonymised `.eml` fixtures + emits `ParsedReceiptDto` with brick/money typed amount | unit | `./vendor/bin/pest --filter=PaypalReceiptMatcher` | ❌ W0 | ⬜ pending |
| 07-02-* | 02 | 1 | EML-07 | T-07-02 | `.eml`/`.mbox` wizard arm extracts + previews + confirms idempotently | feature | `./vendor/bin/pest --filter=WizardEmailFileStep` | ❌ W0 | ⬜ pending |
| 07-03-* | 03 | 2 | EML-05 | T-07-03 | ICS + Google Play matchers reach DTO equivalence with CSV-derived twins | unit | `./vendor/bin/pest --filter=IcsReceiptMatcher --filter=GooglePlayReceiptMatcher --filter=FingerprintParity` | ❌ W0 | ⬜ pending |
| 07-04-* | 04 | 3 | CAT-02 | T-07-05 | `ApplyAutoCategoryStage` runs synchronously inside the pipeline + scores per D-711 + records provenance | unit + feature | `./vendor/bin/pest --filter=ApplyAutoCategoryStage --filter=RuleEvaluator --filter=MerchantMemoryWriter` | ❌ W0 | ⬜ pending |
| 07-05-* | 05 | 4 | CAT-04 | T-07-06 | `/rules` CRUD + correction-divergence toast + drawer inline panel | feature | `./vendor/bin/pest --filter=RulesPage --filter=RuleFormModal --filter=CorrectionDivergence` | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

**Sampling continuity gate:** no 3 consecutive tasks without an `<automated>` verify command. Planner enforces this in PLAN.md `<acceptance_criteria>` blocks.

---

## Wave 0 Requirements

- [ ] Register `Modules/Receipts/tests/Unit/`, `Modules/Receipts/tests/Feature/`, `Modules/Receipts/tests/Contracts/` paths in `phpunit.xml`
- [ ] Anonymised `.eml` fixtures: `Modules/Receipts/tests/fixtures/paypal/*.eml` (≥3 template generations), `Modules/Receipts/tests/fixtures/ics/*.eml` (≥2 generations), `Modules/Receipts/tests/fixtures/googleplay/*.eml` (≥2 generations)
- [ ] Synthetic `.mbox` fixture: `Modules/Receipts/tests/fixtures/mbox/small.mbox` (<1 MB, 5 messages) + `Modules/Receipts/tests/fixtures/mbox/large.mbox` (≥50 MB, streaming proof, optional gitignored — generated by a Wave 0 helper script)
- [ ] `FakeInboxMessageQuery` stub (per Phase 5/6 D-107/D-140 precedent) so CI proves the pipeline without a real Phase 6 backfill
- [ ] `BoundaryArchTest::noEmailFetchFromReceipts` arch test stub
- [ ] CSV ↔ receipt fingerprint-parity test scaffold: `Modules/Receipts/tests/Contracts/FingerprintParityTest.php` with a `dataset()` linking each fixture `.eml` to the corresponding canonical CSV row
- [ ] Anonymisation helper script: `scripts/anonymize_receipt_eml.php` (mirrors `scripts/anonymize_paypal_csv.php` + `scripts/anonymize_ics_text.php`)

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Real Gmail/Graph end-to-end matcher run | EML-05 | Requires Phase 6 OAuth credentials + a populated mailbox — not part of CI | Run the local IMAP polling job once OAuth tokens are present, then `php artisan inbox:process` and confirm `inbox_messages.status` transitions to `parsed` for ≥1 of each (PayPal/ICS/Google Play) message |
| First-conflict toast UX flow | CAT-02 (adjacent) | Toast timing + dismissal feel is subjective; not worth a Dusk test budget for v1 | Trigger a known fingerprint conflict in dev, observe single toast, click an action, confirm `users.receipt_conflict_resolution` is set, trigger a 2nd conflict, confirm no second toast fires |
| Watched-folder cadence (D-704 / D-718) | EML-07 | Verifies the scheduler timing, not pipeline correctness | Drop a sample `.eml` into `storage/app/inbox-drop/`, wait ≤5 min, confirm the file moves to `storage/app/inbox-drop/processed/{YYYY-MM}/` and a row appears in `file_imports.status='parsed'` |

---

## Validation Sign-Off

- [ ] All planned tasks have `<automated>` verify or are explicit Wave 0 dependencies
- [ ] Sampling continuity gate: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references (fixtures, arch tests, parity scaffold, anonymisation script)
- [ ] No watch-mode flags in any task commands
- [ ] Feedback latency: quick command < 30 s, full suite < 60 s
- [ ] `nyquist_compliant: true` set in frontmatter once planner cross-links every task to a Validation Axis

**Approval:** pending — planner cross-links axes during Step 8, gsd-plan-checker validates Dimension 8 coverage during Step 10.
