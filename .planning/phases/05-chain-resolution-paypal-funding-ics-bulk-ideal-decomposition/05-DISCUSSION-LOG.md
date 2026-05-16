# Phase 5: Chain Resolution (PayPal Funding + ICS Bulk-iDEAL Decomposition) - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-05-16
**Phase:** 5-Chain Resolution (PayPal Funding + ICS Bulk-iDEAL Decomposition)
**Areas discussed:** Review queue UX + auto-promotion, Chain drill-down UI surface, ICS settlement: data model + forecast surface, Resolver execution model (sync vs async)

---

## Review queue UX + auto-promotion

### Where the review queue lives

| Option | Description | Selected |
|--------|-------------|----------|
| Dedicated `/chains/review` page (Recommended) | Single batchable list with confirm/reject affordances; matches Phase 4's "one place to triage" posture | |
| Inline on `/transactions/{id}` only | No separate queue — review surfaces only when the user opens a transaction that has pending candidates | |
| Both — dedicated page + inline action | `/chains/review` + a candidate indicator inline; maximises discoverability | ✓ |

**User's choice:** Both. Powers the dual surface — `ConfirmChainLink` / `RejectChainLink` actions called from both.

### Auto-promotion threshold

| Option | Description | Selected |
|--------|-------------|----------|
| After 3 confirmations (Recommended) | Conservative; matches research/ARCHITECTURE.md | ✓ |
| After 1 confirmation | Aggressive learning; one misclick poisons future matches | |
| Never auto-promote | Safest; review queue never shrinks on its own | |

**User's choice:** After 3 confirmations.

### Auto-promotion signature scope

| Option | Description | Selected |
|--------|-------------|----------|
| Per merchant + funding-source pair (Recommended) | `(normalized_merchant, funding_source_identity)` tuple | ✓ |
| Per funding-source only (merchant-agnostic) | Risky: confirm any PayPal→ASN-5678 link once, all future PayPal expenses default that way | |
| Per merchant only | Loses the funding-chain specificity that is Phase 5's whole point | |

**User's choice:** Per merchant + funding-source pair.

### Reject semantics

| Option | Description | Selected |
|--------|-------------|----------|
| Rejected for this exact pair, signature stays neutral (Recommended) | Per-pair rejection; signature still surfaces future candidates | ✓ |
| Reject permanently blacklists the signature | Aggressive negative learning; mistakes hard to undo | |
| Rejected + decay — signature requires N more confirmations to re-propose | Most nuanced; hardest to explain in UI | |

**User's choice:** Per-pair rejected, signature neutral.

---

## Chain drill-down UI surface

### Chain home

| Option | Description | Selected |
|--------|-------------|----------|
| Inline panel on `/transactions/{id}` (Recommended) | Reuses Phase 3 TransactionDetail layout; no new route | |
| Dedicated `/chains/{transactionId}` page | Full-page chain; adds a navigation hop | |
| Side-drawer modal triggered from a "View chain" button | Drawer slides in; closes back to context; project's first Flux drawer primitive | ✓ |

**User's choice:** Side-drawer modal.
**Notes:** Introduces the project's first Flux drawer primitive — flagged for UI-SPEC plan-phase pass to lock open/close/escape behaviour and snapshot baselines.

### Chain visual style

| Option | Description | Selected |
|--------|-------------|----------|
| Vertical waterfall (Recommended) | Top = merchant, downward = funder; pure Tailwind + Blade | ✓ |
| Indented tree (file-explorer style) | Easier to extend for fan-outs | |
| Horizontal flow (left-to-right arrows) | Hardest to keep calm; admin-panel feel | |

**User's choice:** Vertical waterfall.

### Auto-expand depth

| Option | Description | Selected |
|--------|-------------|----------|
| Fully expanded by default (Recommended) | UI-02 says "see exactly where the money came from" — literal honoring | ✓ |
| One level deep, expand-on-click | More clicks to reach the ASN/ICS bottom | |
| Two levels deep, then expand | Compromise; hides bulk-settlement layer | |

**User's choice:** Fully expanded by default.

### Candidate rendering in tree

| Option | Description | Selected |
|--------|-------------|----------|
| Render dimmed with inline Confirm/Reject chips (Recommended) | Contextual action; reinforces the dual surface | ✓ |
| Render dimmed but read-only — confirm only from /chains/review | Cleaner tree; extra navigation hop | |
| Hide unconfirmed legs entirely | Most honest about confidence; UI-02 undermined | |

**User's choice:** Dimmed with inline chips.

### ICS bulk-settle fan-out rendering

| Option | Description | Selected |
|--------|-------------|----------|
| Show all N as nested list under the settlement node (Recommended) | Honest about structural reality; matches research's "covered 23 ICS charges" example | ✓ |
| Show only matched charge + sibling-summary line | Tree stays focused | |
| Collapse to single settlement node | Cleanest tree; hides the painful-cross-source piece | |

**User's choice:** All N as nested list.

### Confidence indicator

| Option | Description | Selected |
|--------|-------------|----------|
| Three-tier chip: Deterministic / Confirmed / Candidate (Recommended) | Hides raw 0–1 confidence float | ✓ |
| Numeric confidence | Most transparent; feels cold | |
| Only "Candidate" badge on unconfirmed | Most minimal; loses deterministic/confirmed distinction | |

**User's choice:** Three-tier chip.

### Entry point on /transactions

| Option | Description | Selected |
|--------|-------------|----------|
| On `/transactions/{id}` only (Recommended) | Keeps `/transactions` list calm; no per-row affordance | ✓ |
| Inline icon on every list row + detail page | Faster path; clutters calm list | |
| Only via inline candidate indicator | Risky: confirmed-chain transactions become inaccessible | |

**User's choice:** Detail-page-only entry.

### Drawer behavior on tall chains

| Option | Description | Selected |
|--------|-------------|----------|
| Drawer scrolls vertically; sticky header (Recommended) | Native Flux drawer pattern | |
| Full-height — chain renders top-to-bottom without scrolling, fan-outs paginate | Deliberate departure from typical drawer | ✓ |
| Caps depth at 3 levels visible; deeper opens new drawer | Tiny per drawer; loses unified-chain feel | |

**User's choice:** Full-height with fan-out pagination.
**Notes:** Deliberate departure from default drawer pattern — UI-SPEC plan-phase locks pagination shape + "X of N" affordance.

---

## ICS settlement: data model + forecast surface

### Statement entity model

| Option | Description | Selected |
|--------|-------------|----------|
| First-class `card_statements` table (Recommended) | Back-populated from Phase 3's `statement_summaries`; Pitfall 4 strongly recommends | ✓ |
| Derive on-the-fly from `statement_summaries` + chain_links | Lighter schema; harder to model carry-forward | |
| Compute from transactions only | Most minimal; least honest; Pitfall 4 warns against | |

**User's choice:** First-class `card_statements` table.

### Carry-forward credit model

| Option | Description | Selected |
|--------|-------------|----------|
| Virtual `credit_carry` line on next card_statement (Recommended) | Matches Pitfall 4's recommended model; mirrors how Mijn ICS displays credit | ✓ |
| Separate `ics_credit_ledger` rolling balance | Cleaner separation; second mutable state surface | |
| Ignore carry-forward in v1 | Simplest; least accurate forecast | |

**User's choice:** Virtual `credit_carry` line on next statement (stored on `card_statement_credits` table for audit).

### Forecast surface

| Option | Description | Selected |
|--------|-------------|----------|
| Dedicated tile on dashboard "this month at a glance" (Recommended) | Same calm tile pattern Phase 3 added per-currency rows under | ✓ |
| Banner at the top of `/transactions` when ICS account selected | Discoverability suffers | |
| Dedicated `/ics` page | Most room for detail; violates UI-01's calm posture | |

**User's choice:** Dashboard tile.

### Bulk-settle reconciliation behavior

| Option | Description | Selected |
|--------|-------------|----------|
| Auto-confirm within ±€5 / ±2% tolerance, delta becomes carry-forward (Recommended) | CHN-05 + Pitfall 4 tolerance values | ✓ |
| Always leave as candidate if not exact-match | Most conservative; review queue fills with near-perfect matches | |
| Auto-confirm always; flag delta in extras for audit | Cleanest UX; loses user-confirmation gate | |

**User's choice:** Auto-confirm within tolerance, delta → carry-forward.

---

## Resolver execution model (sync vs async)

### Sync vs async

| Option | Description | Selected |
|--------|-------------|----------|
| Synchronous (Recommended for pace) | Inside ConfirmImport transaction; keeps Phase 1–4 simplicity | |
| Async via database queue driver (research recommendation) | Matches research/ARCHITECTURE; pulls queue infra forward by one phase | ✓ |
| Synchronous now with explicit async-promotion hook for Phase 6 | Best of both; ships fast | |

**User's choice:** Async via queued job.

### Slow-path UX

| Option | Description | Selected |
|--------|-------------|----------|
| Show "Resolving chains…" progress state in the wizard (Recommended) | Honest; user can wait without wondering | ✓ |
| Defer cross-account resolution to manual "Resolve chains now" button | Risks chains never resolving if user never clicks | |
| Run unconditionally with no progress indicator | Out of character with calm aesthetic | |

**User's choice:** Progress state in wizard. **Translated for async:** wizard polls (`wire:poll`) job status; surfaces "Resolving chains…" while queued/running.

### Resolver scope per dispatch

| Option | Description | Selected |
|--------|-------------|----------|
| Re-scan all open card_statements + all unmatched candidates for this user (Recommended) | Catches late-arriving ICS statement case | ✓ |
| Only this import's transactions + immediate counterparts | Faster; misses retroactive matching | |
| Only this import's transactions — manual button for retroactive | Lowest implicit work; most user friction | |

**User's choice:** Full-user re-scan.

### Wave 0 enablement

| Option | Description | Selected |
|--------|-------------|----------|
| Synthesize a cross-source matching fixture in Wave 0 (Recommended) | Phase 2/3/4 fixtures don't share an iDEAL-settlement pair; synthesis fills the gap | ✓ |
| Use existing fixtures + hand-crafted Pest datasets | Faster Wave 0; less faithful to real-import path | |
| Wait for user to upload matched real exports during Wave 1 | Highest risk; broken decomposer might only surface months later | |

**User's choice:** Synthesize cross-source fixture in Wave 0.

### Queue scope (follow-up after async choice)

| Option | Description | Selected |
|--------|-------------|----------|
| Minimal: database driver + manual queue:work + wire:poll (Recommended) | Defers launchd/plist to Phase 6 | |
| Full: database driver + launchd plist + /jobs surface + failed-jobs UI | Pulls Phase 6 work forward | |
| Synchronous wrapper in fake-job shape | Contradicts the async choice | |
| **Other (user-typed):** "use horizon" | User wants Laravel Horizon | ✓ |

**User's choice (free-text):** "use horizon" — user wants Laravel Horizon's production-grade queue dashboard. This conflicts with PROJECT.md "What NOT to Use → Laravel Horizon for this project" (which rules Horizon out because it needs Redis). Flagged to the user; user explicitly chose to override the PROJECT.md stack decision.

### Horizon vs database (override confirmation)

| Option | Description | Selected |
|--------|-------------|----------|
| Stick with database queue + minimal failed-jobs toast (Recommended) | No Redis dependency; matches PROJECT.md STACK | |
| Override STACK — introduce Redis + Horizon in Phase 5 | Production-grade observability; adds Redis daemon dependency; contradicts the "no Redis" constraint | ✓ |
| Horizon UI ONLY with database driver | Not real — Horizon is hard-coupled to Redis | |

**User's choice:** Override STACK to add Redis + Horizon.

### Redis source

| Option | Description | Selected |
|--------|-------------|----------|
| brew install redis + launchd plist (Recommended) | Self-contained; keeps project on free Herd | |
| Herd Pro Redis service | Native Herd UI; requires Pro license | |
| Docker Redis container | Re-introduces Docker (project explicitly avoided this for Sail) | ✓ |

**User's choice:** Docker Redis container.
**Notes:** Carved as an exception to PROJECT.md's "no Docker" rule — network-only Redis service does not invoke the Sail bind-mount anti-pattern. Plan-phase emits the PROJECT.md amendment alongside other STACK edits.

---

## Claude's Discretion

Areas where defaults were taken or planner gets flexibility (full list in CONTEXT.md "Claude's Discretion" subsection):

- Exact Flux drawer keyboard / animation / open-close behaviour (UI-SPEC plan-phase)
- Migration timestamp slots and order against existing Phase 4 migrations
- Exact JSON shape of `chain_link.evidence` for ICS bulk-settle + refund-after-close
- Exact wire:poll interval (default 2s)
- Wave 0 synthesised scenario sizing (default 20–25 ICS transactions covered by bulk-iDEAL)
- `/horizon` dashboard auth gating (Phase 1 LoopbackOnly + Fortify covers this; Horizon-specific auth gate is v2)
- Whether `Modules/Transfers/Public/PairLookup` ships in Phase 5 Wave 0 or as a Wave 2 prerequisite

## Deferred Ideas

(Captured in CONTEXT.md "Deferred Ideas" — preserves both genuinely-out-of-scope items AND the rejected alternatives from each gray area for future revisiting if those choices need to be walked back.)
