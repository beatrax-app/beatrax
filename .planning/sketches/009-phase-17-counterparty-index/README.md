---
sketch: 009
name: phase-17-counterparty-index
question: "How are all counterparties listed on /counterparties — dense list, card grid, or ledger table? Type-filter chip UX."
winner: "D"
tags: [counterparties, index, filter, phase-17]
---

# Sketch 009: Counterparty index page

## Design Question

`/counterparties` is the entry point. 328 entities to wrangle, with type-filter chips, search, sort, and bulk actions on the table when applicable. What composition fits the calm-slate aesthetic AND the variety of types AND the user task ("find the merchant I want to look up; or batch-identify a bunch of unknowns")?

## How to View

```
open .planning/sketches/009-phase-17-counterparty-index/index.html
```

## Variants

- **A — Dense Linear list** — One row per counterparty in a single bordered container. Avatar + name + type chip + meta line + 12-month total right-aligned. Actions reveal on hover. Most space-efficient; feels like Linear's issue list.

- **B — Card grid** — 3-col responsive grid. Each counterparty = card with avatar, name, type chip, two stats, a mini sparkline (12 months of activity), and a one-line recent transaction. Most visual. Unknown counterparties get a distinct dashed-border treatment with a "Label this counterparty" CTA built into the card.

- **C — Ledger table** — Spreadsheet-feeling. Sortable column headers (Tx, 12mo total, Avg/mo, Last seen). Checkbox-per-row enables bulk actions (Set category, Merge, Export, Mark as ignored). Most familiar to power users; best for batch operations.

- **★ D — Synthesis: cards (default) + list toggle** — Cards view from B is the default (better for discovery, sparklines give at-a-glance signal). A segmented `Cards | List` toggle in the toolbar swaps to A's dense list view (denser when you want to scan many rows). Same toolbar, same chips, same data, same type colors — only the body composition changes. C (ledger + bulk-edit) is dropped; bulk operations move to a future Dev Mode action if needed.

## Shared across all three

- Same page head ("Counterparties · 328 entities · 61 need identification")
- Same search box (`/` shortcut)
- Same type-filter chip row (All · Merchants 241 · Personal 12 · Banks 4 · Government 7 · Self 3 · Unknown 61) — each chip has the type color dot + count
- Same type-chip color treatment from sketch 008 (blue=merchant, pink=personal, amber=bank, slate=gov, gray=self, dashed=unknown)
- Personal IBANs hidden (italic "— hidden —" or "IBAN hidden" hint)
- Self-account routes to Accounts view via its row/card action

## What to Look For

1. **Information density vs scannability** — at 328 counterparties, A and C scale linearly; B (card grid) starts to feel sparse after ~40 cards. Does B work for the typical viewer or does it need a "compact / cards" toggle?
2. **Type filter chip visibility** — same chip row in all three. Does the dot+count layout feel right, or should counts be implicit (just dot+label)?
3. **Bulk action discovery** — only variant C exposes bulk operations naturally (checkboxes). Variants A and B would need a different affordance (e.g., a "Bulk edit" mode toggle). Is bulk-edit important enough for that to matter?
4. **Unknown-counterparty treatment** — A shows a "Label this" hover button; B has a dashed-border card with built-in CTA; C just shows the row as italic muted. Which makes triaging unknowns feel most natural? (This bleeds into sketch 010.)
5. **Self-account row** — A and B have an "Open account →" action; C marks the row as "routing only" with no total. Is "Self" worth surfacing in the index at all, or should it be hidden by default with a filter chip to opt-in?
6. **Sortability** — only C has visible sort controls. A and B show "Sort: Total 12mo ↓" as a quiet right-aligned link. Is that enough or does B/A need a real sort dropdown?

## Open questions

- Should the page have a "Group by type" toggle that organizes rows under collapsible section heads (Personal / Banks / Government / Merchants / Unknown)?
- Should the unknown counterparty count badge in the sidebar (`Counterparties 328`) call out the unknowns specifically (e.g., `328 · 61 ?`) to nudge triage?

## What's NOT being decided

- The label-this-unknown flow (sketch 010)
- Counterparty profile page (007/008 winners apply)
- Counterparty merge UX (Dev Mode action, deferred)
