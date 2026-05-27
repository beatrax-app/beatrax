---
sketch: 010
name: phase-17-identify-unknown-flow
question: "Where does the 'label this unknown counterparty' CTA live, and what does the flow feel like at the different entry points?"
winner: "B"
tags: [counterparties, triage, identification, phase-17]
---

# Sketch 010: Identify-unknown flow

## Design Question

61 unknown counterparties need labeling. A user labeling one (or many) needs:
- Preview of the unknown (IBAN + recent descriptions + tx count) for context
- Either **match to existing** counterparty (when the unknown is an alias) or **create new** (with type picker)
- "Apply to all matching" option for bulk reattribution
- Privacy-aware type defaults (personal hides IBAN)

The deeper question is **velocity profile** — when, where, and how often does this happen?

## How to View

```
open .planning/sketches/010-phase-17-identify-unknown-flow/index.html
```

## Variants (different velocity profiles)

- **A — Contextual modal** *(low-velocity, in-the-moment)* — User is reviewing transactions, sees an unknown counterparty row, clicks "Label". A 560px modal pops over the page. Has both "Match existing" + "Create new" panes via segmented toggle. "Apply to all 3 matching" checkbox enabled by default. Best for: "I noticed this in the wild and want to label it now." Same modal can be reached from any unknown surface (transaction row, profile page, index row).

- **B — Dedicated triage queue** *(high-velocity, focused bulk)* — `/counterparties/triage` is a full-page sit-down-and-clear-the-queue experience. One unknown at a time, big card, progress bar at top ("23 of 61 · ~15 min remaining"). **Suggestion-driven** ("Looks like Ziggo — confidence high") with reasoning surfaced. Keyboard-first: `Y` / `N` / `S` / `→`. Best for: "I'll sit down for 15 minutes and clear my unknowns."

- **C — Inline editor on the index** *(medium-velocity, browsing)* — Filter the counterparty index to type=Unknown. Click any row to expand an inline editor in-place with smart defaults (autocomplete name + type select + apply-to-all). `Tab` moves to the next unknown. No page navigation, no modal — stay in the index. Best for: "I'm already in the counterparty index, let me knock a few off while I'm here."

## Shared across all three

- **Preview block** — IBAN (always partially masked) + tx count + total + last seen + description excerpt
- **Match vs Create decision** — match-existing is the first option (often the right answer); create-new is the second
- **Type picker** — 5 options: Merchant / Personal / Bank / Government (Self isn't user-creatable). Personal selection triggers IBAN-hide behavior.
- **Apply to all N matching** — defaults checked; un-check if this is a one-off
- **Note in copy when picking Personal** — "Personal type hides the IBAN from lists and exports"

## What to Look For

1. **Velocity match to the actual task** — for 61 unknowns, B's dedicated queue with suggestions feels right; for an occasional unknown spotted while browsing transactions, A's modal is the natural reach. Are these meaningfully different enough to ship BOTH, or should one win and the others be entry points that share its core?
2. **Suggestion confidence display in B** — "Looks like Ziggo — confidence high" with reasoning surfaced. Does the green suggestion banner feel trustworthy, or pushy?
3. **Inline-editor compactness in C** — does the editor squeeze too much into one row, or is the two-column (name + type) split right? Should it expand to two rows (name across full width, then type + apply-to-all)?
4. **Keyboard ergonomics** — B leans hardest on kbd shortcuts (`Y`/`N`/`S`/`→`), C uses `Tab`/`Esc`, A uses `Enter`/`Esc`. Are the bindings consistent enough across the three?
5. **Are all three needed?** — likely yes (different moments), but worth confirming. A and C share so much logic they should literally render the same Livewire component in two contexts.

## Specific to surface (recommendations)

- A's modal is the **canonical UX**. It is reachable from: transaction row right-click, transaction row hover button, unknown-counterparty profile page primary CTA, and the "label" button in the counterparty index row hover.
- B's triage page is the **dedicated path** linked from the sidebar Triage item (which already exists from Phase 16.1 — it currently scopes to transaction-level triage; this extends it to counterparty-level).
- C's inline-editor is the **in-flow shortcut** — it triggers when user clicks an unknown row on the counterparty index, instead of A's modal. Faster for batch.

## What's NOT being decided

- Counterparty merge UX (two known counterparties → one) — Dev Mode action, deferred to v1.1.
- Community corpus suggestions on B's "what to label this as" step — Phase 16.1 sketch 005 handles the community-facing flow separately. They could integrate later but stay decoupled in v1.0.
- Bulk select + bulk-label without identification (i.e., "ignore these 12") — possible feature but not in this sketch.
