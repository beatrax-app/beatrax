---
sketch: 008
name: phase-17-counterparty-profile-type-variants
question: "How does the 007C tabbed shape flex across personal / bank / government / self_account types?"
winner: "all four — shape holds"
tags: [counterparties, profile, types, privacy, phase-17]
---

# Sketch 008: Counterparty profile · type variants

## Design Question

The 007C tabbed shape (`Overview · Transactions · Chains · Aliases`) was validated for the **merchant** baseline. This sketch asks: does that shape **flex gracefully** for the four other counterparty types, or do any of them want a fundamentally different page?

This is a different sketch pattern than usual — the "variants" ARE the four types. The point is not to pick a winner but to **confirm the shape holds**, surface any type-specific decisions, and lock in the privacy-defaults for `personal`.

## How to View

```
open .planning/sketches/008-phase-17-counterparty-profile-type-variants/index.html
```

## Variants

- **A — Personal (Mama)** — pink type chip, privacy banner at top, IBAN hidden behind a "Show IBAN" button, `Net received` headline stat (P2P direction), purpose tags instead of categories (`birthday`, `rent split`, `groceries shared`), `Direction summary` cards (↓ received / ↑ sent). Tab bar drops Chains; gains nothing. Recurring section replaced with a quiet "no recurring detected" frame.

- **B — Bank (ASN Bank fees & interest)** — amber type chip with `fees & interest` subtitle to disambiguate from "ASN Bank as account holder". Fee-type aggregation with horizontal mini bars (`Account fee`, `ATM withdrawal`, `FX conversion`, `Card replacement`, `Interest income`). YTD vs prior 12mo. Recurring section shows monthly/quarterly bank fees. Tab bar drops Chains. Footer link "for the account balance view, open Accounts → ASN Bank".

- **C — Government (Belastingdienst)** — slate type chip. **Tax-year breakdown is the headline** — full-width row of three year cards (2026 YTD / 2025 final / 2024) above the Overview grid. Current year card has emphasized border + a pending-assessment chip. By-tax-type breakdown (Inkomstenbelasting / BTW / Motorrijtuigenbelasting / Toeslagen). Adds a `Tax years` tab. Drops Chains.

- **D — Self-account (PayPal as counterparty)** — minimal hero, **stub redirect**: a dashed-frame block explaining "this isn't really a counterparty, it's your own account" with a "Open PayPal account view →" primary action. Below it a small "recent cross-account legs" table for context. No tabs (no Overview to populate). The whole page is a routing hint.

## What to Look For

1. **Does the 007C shape hold across A / B / C?** The tabs vary (drops Chains for non-merchants; gains `Tax years` for government), but the hero + tab-bar + 2-col Overview grid stays the same. Confirm or push back.
2. **Is the privacy-default treatment for personal (variant A) right?** Privacy banner at top + IBAN hidden + "Show IBAN" button + auto-hide on page leave. Strong enough? Too strong?
3. **Does the self-account stub (D) make sense, or should self-account counterparties simply not exist as profile pages at all** — i.e., the click-through from a transaction row to a self-account just goes straight to the account view, no intermediate page?
4. **Is the bank disambiguation (ASN Bank fees vs ASN Bank account holder) confusing?** The subtitle "fees & interest" tries to clarify but it's a real conceptual hairy spot.
5. **Government tax-year card row** — is the full-width 3-year strip the right headline, or should it live inside a single Overview-grid cell?
6. **Universal action affordances** — does every type need the same `✎ edit` button? Or do some types make sense to NOT allow renaming (e.g., government — "Belastingdienst" is canonical, you wouldn't rename it)?

## Open questions for sketches 009/010

- For the `Unknown` type (not shown here — that's sketch 010's domain): what's the profile shape? Probably a stub similar to D plus a prominent "Help me identify this" CTA.
- The counterparty index (sketch 009) needs to surface type at-a-glance; this sketch's type-chip color treatment is the candidate visual language.

## What's NOT being decided in this sketch

- The counterparty index page (sketch 009)
- The unknown-counterparty triage flow (sketch 010)
- Exact `Tax years` tab content (just hinted at)
- Edit-counterparty UX for renaming/merging
