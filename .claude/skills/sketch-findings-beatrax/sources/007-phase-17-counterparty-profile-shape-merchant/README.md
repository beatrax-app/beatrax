---
sketch: 007
name: phase-17-counterparty-profile-shape-merchant
question: "What's the overall composition of a counterparty profile page for the merchant baseline?"
winner: "C"
tags: [counterparties, profile, layout, phase-17]
---

# Sketch 007: Counterparty profile shape (merchant)

## Design Question

What's the right overall composition for a counterparty profile page when the counterparty is a **merchant** (the baseline type)? This shape will be the template that the four other types (`personal`, `bank`, `government`, `self_account`) flex from in sketch 008.

A counterparty profile needs to expose, at minimum:
- Hero identity (name, type, key totals)
- Aliases (the resolved name + alternate names that map to it)
- Category breakdown (what the user spends on at this merchant)
- Recurring patterns (subscription detection hits)
- Funding chain (how money reaches this merchant — Phase 16.1.2.1 territory)
- Transaction list (the full ledger filtered to this counterparty)

How should those sections compose into a single page?

## How to View

```
open .planning/sketches/007-phase-17-counterparty-profile-shape-merchant/index.html
```

(Or use the toolbar bottom-right to flip light/dark + clamp viewport widths.)

## Variants

- **A — Stacked single column** (~920px) — Linear-style. Hero at top, then every section stacks below at full width: aliases · categories · recurring · funding chain · transactions. Maximum density on one scroll; reads like a long article.
- **B — Hero + 2-column body** — Hero full-width. Below: left aside (~320px) holds meta sections (aliases, categories, recurring, funding chain); right column (1fr) is dedicated to the transaction list. Two surfaces side by side.
- **C — Tabbed surface** — Compact hero. Tab bar (`Overview · Transactions · Chains · Aliases`). Overview shows category + recurring + 5-row recent activity + funding-chain summary in a 2-col grid. Each detail tab gets the full page.

## Content (consistent across variants)

Amazon as the example counterparty:
- 5 aliases (`Amazon`, `AMAZON EU SARL`, `AMAZON.NL`, `AMZN Mktp NL`, `Amazon Payments`)
- 12 transactions across May / Apr / Mar 2026
- Mixed payment types (online, recurring, PayPal-funded)
- Multi-currency (one $20.50 → €18.99 row)
- 2 recurring patterns detected (Amazon Prime yearly, Kindle Unlimited monthly)
- Funding chain: ASN → ICS Cards → Amazon (primary) + ASN → PayPal → Amazon (occasional)
- 4 categories (Household 44 % · Books & media 28 % · Electronics 18 % · Other 10 %)

## What to Look For

1. **Information density vs scannability** — does single-column scroll (A) feel right, or does the 2-col split (B) reduce cognitive load? Does C's tabbing hide the wrong things?
2. **Where the transaction list lives** — in A it competes with all other sections for attention. In B it gets a dedicated column. In C it gets a dedicated tab.
3. **How the funding chain reads** — the chain is a Phase 17 selling point ("see where the money truly came from"). Does it feel prominent enough in A (mid-page section)? Cramped in B's narrow aside? Buried in C (overview-only summary + own tab)?
4. **Recurring patterns visibility** — a `personal` profile won't have recurring, a `bank` won't either, so wherever this lives needs to gracefully disappear for those types.
5. **Action affordance** — where would "merge this counterparty with another" or "set a default category" naturally live? Aliases section already hints at the right hover-affordances.

## Reused primitives (no design decisions to re-make here)

- Sidebar (sketch 001 winner — sectioned + Dev block)
- Payment-type chips (sketch 004 winner — glyph+word format)
- Frame block on `bg-subtle` (sketch 006 winner)
- Slate/emerald/amber/rose token palette + JetBrains Mono identifiers + tabular numerics

## What's NOT being decided in this sketch

- Type-specific shape variations (sketch 008)
- The counterparty index page (sketch 009)
- How an unknown counterparty gets labeled (sketch 010)
