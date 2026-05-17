# Phase 7: Email Template Matchers + Categorization Learning - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-05-17
**Phase:** 07-email-template-matchers-categorization-learning
**Areas discussed:** Matcher architecture & module home, .eml/.mbox drop-in entrypoint, Dedup policy (email vs CSV), Rule + memory precedence

---

## Phase Target Confirmation

| Option | Description | Selected |
|--------|-------------|----------|
| Phase 7 (next: email matchers) | Discuss Phase 7 now — pin gray areas so planning is unblocked the moment Phase 6 ships | ✓ |
| Phase 8 (recurring detection) | Skip ahead; depends on ≥3 months of imported history | |
| Finish Phase 6 first | Hold off until 06-08 and 06-09 land | |

**User's choice:** Phase 7.
**Notes:** Phase 6 plans 08 and 09 still in flight; user wants Phase 7 context locked in parallel.

---

## Matcher Architecture & Module Home

### Question 1: Module placement

| Option | Description | Selected |
|--------|-------------|----------|
| New `Modules/Receipts/` module | Dedicated bounded module for matchers + email-to-tx pipeline + categorization extension; mirrors Modules/Transfers/ + Modules/Chains/ | ✓ |
| Modules/Ingestion/ with SourceAdapter per sender | Reuse existing SourceAdapter pipeline; each sender is another SourceAdapter | |
| Split: matchers in Receipts/, categorization in Categorization/ | Two surfaces; clean separation | (followed up — categorization side went to Categorization in Area 4) |

**User's choice:** New `Modules/Receipts/`.
**Notes:** Phase 6 boundary test (`noTransactionWritesFromEmailScan`) forces a new home for the matcher-to-transaction path. Phase 7 inverts the boundary with a new `noEmailFetchFromReceipts` invariant.

### Question 2: Matcher implementation style

| Option | Description | Selected |
|--------|-------------|----------|
| Per-sender PHP class implementing `SenderMatcher` | Typed DTO output, container-tagged registry, isolated unit tests; aligns with DI-only invariant | ✓ |
| Config-driven YAML/PHP templates | Lower barrier to add senders; harder to type-check | |
| Hybrid: PHP class with embedded config sections | Middle ground | |

**User's choice:** Per-sender PHP class.

### Question 3: Receipt → Transaction handoff

| Option | Description | Selected |
|--------|-------------|----------|
| Feed into the existing SourceAdapter pipeline | Reuse FingerprintComposer v3 + cross-format dedup + RecordTransactions | ✓ |
| Dedicated Public Action in Receipts/ that writes Transaction | Less wiring; risk of drift from locked Phase 2 dedup contract | |

**User's choice:** Reuse the existing pipeline.
**Notes:** This is the load-bearing decision for "cross-format dedup just works." Receipt and CSV produce fingerprint-equivalent transactions.

---

## .eml / .mbox Drop-In Entrypoint (EML-07)

### Question 1: Drop-in UX

| Option | Description | Selected |
|--------|-------------|----------|
| Existing /imports wizard with new "Email file (.eml/.mbox)" issuer | Reuses preview→confirm flow + idempotency contract | |
| Watched folder under storage/app/inbox-drop/ | Background scan + move-to-/processed/ | |
| Both — wizard primary, watched folder as power-user option | Wizard documented; folder behind /settings toggle | ✓ |

**User's choice:** Both — wizard primary, folder secondary (default off).

### Question 2: .mbox handling

| Option | Description | Selected |
|--------|-------------|----------|
| Iterate — persist each message as inbox_messages row + .eml on disk | Mix file-drop + API-fetch into the same index table | |
| Iterate in-memory — run matchers without persisting | Smaller storage; no re-parse, no audit | |
| Iterate — persist to a separate `file_imports` table, not inbox_messages | Clean Phase 6/Phase 7 boundary: API vs file-drop kept separate | ✓ |

**User's choice:** Separate `file_imports` table.
**Notes:** Keeps the Phase 6 vs Phase 7 boundary semantically clean. Same status lifecycle (`fetched`/`parsed`/`skipped`/`unmatched`), idempotency via RFC 822 `Message-ID` with sha256-of-body fallback when absent.

---

## Dedup Policy: Email vs CSV-Imported

### Question 1: Enrichment policy on conflicting fields

| Option | Description | Selected |
|--------|-------------|----------|
| First write wins; receipt only fills nullable fields | Predictable; receipt-side richer data lost when CSV arrived first | |
| Per-source-precedence map: receipt > PayPal CSV > bank CSV | Better data, more policy complexity | |
| User chooses on first conflict, then remembers | One-time toast + per-user setting; calm aesthetic preserved | ✓ |

**User's choice:** User chooses on first conflict, then remembers.
**Notes:** New `users.receipt_conflict_resolution` column; first conflict surfaces a toast; setting then applies silently. Per-field precedence map deferred to v2.

### Question 2: Chain hint feed

| Option | Description | Selected |
|--------|-------------|----------|
| Populate reference_id; trust Phase 5's existing job | Zero new code in Chains | |
| Emit explicit ChainHintDetected events | Richer; inter-module coupling | |
| Both — reference_id for the common case, event for rich hints only | reference_id covers SC#1; event opt-in per matcher for funded-by/refund-of hints | ✓ |

**User's choice:** Both.
**Notes:** Matchers always populate `reference_id`; emit `ChainHintDetected` only when extracting structured cross-source clues that don't fit a single field.

---

## Rule + Memory Precedence (CAT-02 + CAT-04)

### Question 1: Rule shape

| Option | Description | Selected |
|--------|-------------|----------|
| Field-selector + match-type + value (no regex) | Non-technical-user form; covers SPOTIFY-contains case cleanly | ✓ |
| Field-selector with regex as alt match-type | Power-user; regex risk surface | |
| Free-form text query (DSL) | Most flexible; heaviest cost | |

**User's choice:** Field-selector + match-type + value.
**Notes:** Multi-condition + regex deferred to v2.

### Question 2: Rule engine home

| Option | Description | Selected |
|--------|-------------|----------|
| Extend Modules/Categorization/ | One home for rule + memory; uniform application across all sources | ✓ |
| Rule engine in Modules/Receipts/, memory in Categorization/ | Asymmetric; risk of duplicating rule logic | |

**User's choice:** Extend Modules/Categorization/.

### Question 3: Rule vs memory precedence

| Option | Description | Selected |
|--------|-------------|----------|
| Rule wins (explicit > implicit) | User-authored intent wins; predictable | |
| Memory wins (recent behavior > old rule) | Lately-clicked wins | |
| Higher specificity wins (rule-then-memory as tiebreaker) | Specificity-scored; explicit-equals > learned-merchant > starts_with > contains | ✓ |

**User's choice:** Higher specificity wins (rule-then-memory tiebreaker).
**Notes:** Memory still grows when a rule wins so deleting a rule restores learned behaviour. Provenance is recorded with each auto-categorization for the correction-divergence flow.

### Question 4: Correction-divergence UX

| Option | Description | Selected |
|--------|-------------|----------|
| Toast immediately after the user saves their correction | Phase 4/5 toast precedent; calm | |
| Inline action in the transaction detail drawer | Always-visible affordance; lower interrupt | |
| Passive: log to a "Rule conflicts" review page | Lowest interruption; easy to ignore | |
| Toast + inline panel (most discoverable) | Two surfaces, same action target | ✓ |

**User's choice:** Toast + inline panel.

### Question 5: Rules UI placement

| Option | Description | Selected |
|--------|-------------|----------|
| New top-nav /rules page | One domain → one page (calm-aesthetic discipline) | ✓ |
| Tab inside /settings | Co-locate with admin knobs | |
| Tab inside /uncategorized | Mixes per-tx triage with global rule authoring | |

**User's choice:** New top-nav /rules page.
**Notes:** Top-nav crowding flagged for UI-SPEC pass — may introduce a "Categorize" submenu if needed.

---

## Claude's Discretion

- Wave structure (Wave 0 fixtures + arch tests; Wave 1 PaypalReceiptMatcher vertical slice; Wave 2 Ics + GooglePlay; Wave 3 ApplyAutoCategoryStage + MerchantMemoryWriter; Wave 4 rules CRUD + /rules page + correction-divergence UX). Planner verifies goal-backward.
- Storage shape for pending-conflict state in D-707 (new table vs column on existing pending_enrichments). Planner picks against Phase 2 precedent.
- Matcher container-tag name + multi-match priority strategy.
- Synthetic provider_message_id hash function (sha256 suggested).
- Watched-folder cadence (5 min suggested).
- Synchronous vs queued ApplyAutoCategoryStage (synchronous default).
- UI-SPEC pass for /rules + drawer panel (Flux components).
- Top-nav grouping decision.
- Whether categorization_rules has `active` + `notes` (Wave 4 budget permitting).

## Deferred Ideas

- Regex rules + multi-condition rules + free-form DSL — v2.
- Per-field conflict precedence map — v2.
- PDF receipt parsing (some ICS receipts arrive as PDF attachments) — v2.
- Image / photo-of-receipt ingestion — never on roadmap.
- Watched-folder enabled by default — v2.
- Top-nav "Categorize" submenu — UI-SPEC pass.
- `active` toggle + `notes` field on rules — Wave 4 stretch / v2.
- Re-parse trigger from UI for status='unmatched' — Wave 4 stretch / v2.
- Merchant-name normalization (strip store-number suffixes) — planner decides Wave 3 vs v2.
- Re-parse on rule change (retroactive bulk recategorize) — v2.
- PROJECT.md amendment for zbateson/mail-mime-parser — Wave 0.
- Inline "create rule from this transaction" affordance — Wave 4 stretch / v2.
- Confidence indicator (green dot for rule, dim for memory) on transaction rows — UI-SPEC.
- Bulk rule import/export (JSON) — v2.
