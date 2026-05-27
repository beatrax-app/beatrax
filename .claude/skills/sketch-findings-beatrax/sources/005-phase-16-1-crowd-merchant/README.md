---
sketch: 005
name: phase-16-1-crowd-merchant
question: "Where does community merchant identification live and what's the contribute flow look like for a non-technical user?"
winner: A+B+C combined
tags: [community, settings, contribution, triage, phase-16-1]
---

# Sketch 005: Crowd-sourced Merchant Identification Surface

## Winner

**Combined — B is the primary entry, C holds the toggles, A is the
"browse all" destination.** All three share the same suggest-mapping
modal (mystery code + friendly name + optional category hint + region
→ live YAML preview → submits as draft PR from `diederik-bot` so the
user stays anonymous unless they opt in).

The three layers compose like this:

- **B — Triage row CTA (primary entry).** The "❋ Help others
  identify this" dashed button lives next to "Rename & categorise" on
  every mystery-code row in `/triage`. This is where 90% of
  contributions happen — at the moment the user is already thinking
  about the unrecognised merchant. The button is quiet but
  recognisable. Footer line celebrates the user's running
  contribution count.
- **A — `/community/mystery-merchants` (destination).** Sidebar item
  under Categorization with a badge for unidentified codes. Stats
  strip puts the contribution into context (mappings in shared list,
  % of your imports auto-named, your contributions live). Card list
  shows each mystery code in *your* data with payment-type hint and
  seen-count. Reached from the Triage footer link ("Browse all
  mystery codes →") and from the Settings widget's primary CTA.
- **C — `Settings → Shared merchant list` (preferences).** Three
  toggles: use the shared list to auto-name your imports / offer
  contribution buttons on Triage / pull updates on app updates.
  Recent-community-contributions widget gives the corpus a heartbeat.
  Acts as the "find me later" entry point for users who closed the
  Triage tab and want to come back to it.

This layering keeps the affordance discoverable at the moment of use
(B), gives the corpus a destination that justifies its existence (A),
and gives privacy-conscious users explicit control (C).

## Design Question

When the import pipeline can't turn `BCK*SHELL PIETER NIEUW` into
"Shell — Pieter Nieuwlandstraat", we want users to be able to
contribute their fix back to a shared list so everyone running
diederik benefits.

Two hard things at once:

1. **Where in the app does this live?** Dedicated page, embedded in
   the existing triage workflow, or tucked into Settings?
2. **How does a non-technical user contribute to what is technically
   a YAML file in a Git repo?** They don't know what a PR is. They
   shouldn't need to.

Whichever variant wins, the contribute flow itself is the same
shared modal: pre-filled with their mystery code, friendly name +
optional category hint + region; submits as a draft PR from a bot
account so the user is anonymous unless they opt in.

## How to View

```
open .planning/sketches/005-phase-16-1-crowd-merchant/index.html
```

The floating tools include an "Open suggest modal" button so you
can see the contribution form without finding a mystery row first.

## Variants

- **A — Dedicated `/community` page** (sidebar: "Mystery merchants").
  A first-class destination: stats strip (your data has N mystery
  codes / N in shared list / N% of your imports auto-named / N of
  your contributions live), then a card list of mystery merchants
  found in *your* data with seen-count, payment-type hint, and the
  primary "Suggest a name" CTA. Pro: discoverable, makes the corpus
  feel like a real thing. Con: another sidebar item; might feel like
  separate-tab busywork.
- **B — CTA inline on the existing Triage row.** Lives inside the
  existing `/triage` (uncategorized) workflow. Each mystery-code row
  has the normal "Rename & categorise" button plus a quieter, dashed
  "❋ Help others identify this" button that opens the same modal.
  Below the table, a small celebratory line: "You've helped identify
  23 merchants — thanks 🙏" and a link to "Browse all mystery codes"
  (which routes to Variant A's page). Pro: zero new navigation,
  surfaces the contribution at exactly the moment the user is
  thinking about the mystery code; quietly opt-in. Con: easy to miss
  the dashed button.
- **C — Settings section + corpus widget.** Lives under
  `Settings → Shared merchant list`. Explains what the corpus is,
  shows stats, gives three toggles (use the list / offer to
  contribute / auto-update on app updates), plus a recent-community-
  contributions widget. Action to browse mystery merchants routes
  into Variant A's page. Pro: this is where preferences belong, and
  the toggles are useful even if A or B is the contribution surface.
  Con: contribution itself is two clicks away, easy to never visit.

## What to Look For

- **Discoverability vs interrupt.** A is a destination. B catches
  the user mid-flow. C requires the user to wander into Settings.
  How obvious does the contribution path need to be?
- **The "this is a PR to a YAML file in a Git repo" awkwardness.**
  All three variants use the same modal that hides the awkwardness
  with a YAML preview, "submits as draft PR from diederik-bot", and
  "you're anonymous unless you choose to be" copy. Is that enough?
  Does anything else need explaining inline (e.g. how long a merge
  takes, what happens if it's rejected)?
- **The "your data could leak" fear.** The modal note says "we
  strip everything except the code and the name". A and C both
  reiterate this on the page. Is that visible enough in B?
- **Power-user shortcut.** All variants link to a "View shared list
  on GitHub" affordance. Is that the right escape valve?
- **Mode toggle.** Flip to dark — the modal backdrop uses an
  rgba-tinted overlay; the YAML preview's syntax tokens shift
  contrast. Check both modes.
- **Existing sidebar density.** Look at A's sidebar. We already have
  Triage (12) showing a badge; adding "Mystery merchants (5)"
  doubles the badged items in the Categorization group. Is that
  noise or signal?

## Pre-emptive notes

- The "82% of your imports auto-named" stat in A and C is a vanity
  metric in the best sense — it makes the corpus feel concretely
  useful to *this user* before they're asked to contribute.
- The modal's "diederik-bot" PR-author affordance assumes a GitHub
  App or a service account that holds the write token; design
  doesn't depend on which.
- Variant B's "Help others identify this" button uses a `❋` (eight-
  pointed asterisk) glyph as the share-this affordance. Substitute
  for a real icon when implementing.
- All three variants assume the user can't / shouldn't see other
  contributors' raw transaction text — the corpus stores patterns,
  names, and category hints only. That's a privacy contract.
