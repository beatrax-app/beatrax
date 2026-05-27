# Counterparty Index & Triage

The `/counterparties` index page lists every entity that transacts with
the user (~300+ at steady state). The `/counterparties/triage` page is a
dedicated focused-mode UI for labeling the long tail of unknown
counterparties (typically ~60). Both share the same toolbar + type-filter
chip row + type-color language.

## Design Decisions

### Index composition: cards-default + dense-list toggle (sketch 009 winner D synthesis)

The index page surfaces the same counterparties two ways via a
**segmented view-mode toggle** in the toolbar:

- **Cards view (default)** — 3-col responsive grid
  (`grid-template-columns: repeat(auto-fill, minmax(280px, 1fr))`).
  Each card: avatar + name + type chip + 2 stats + mini 12-month
  sparkline + 1-line recent activity. Best for **discovery** — the
  sparkline gives at-a-glance signal (steady monthly = subscription;
  sparse spikes = one-offs; growing trend = changed habit).
- **List view (toggle)** — dense Linear-style rows. One row per
  counterparty in a single bordered container (`background:
  var(--color-surface); border: 1px solid var(--color-border);
  border-radius: var(--radius-lg)`). Grid:
  `grid-template-columns: 32px 1fr auto 130px auto; gap: 14px`. Best
  for **scanning** many counterparties when you know what you're
  looking for.

The toggle lives in the toolbar near the sort link:

```html
<div class="view-toggle">
  <button class="active" data-view="cards">▦ Cards</button>
  <button data-view="list">≡ List</button>
</div>
```

Style (segmented control feel):

```css
.view-toggle {
  display: inline-flex; background: var(--color-surface-2);
  border-radius: var(--radius-md); padding: 2px;
  border: 1px solid var(--color-border);
}
.view-toggle button {
  padding: 4px 10px; font-size: var(--text-xs); font-weight: 500;
  background: transparent; border: none; color: var(--color-text-muted);
  border-radius: var(--radius-sm);
}
.view-toggle button.active {
  background: var(--color-surface); color: var(--color-text);
  box-shadow: var(--shadow-xs);
}
```

### Type-filter chip row

Used identically on the index AND in the triage filter banner:

```html
<div class="filter-chips">
  <button class="chip active">All <span class="chip-count">328</span></button>
  <button class="chip"><span class="chip-dot dot-merchant"></span> Merchants <span class="chip-count">241</span></button>
  <button class="chip"><span class="chip-dot dot-personal"></span> Personal <span class="chip-count">12</span></button>
  <button class="chip"><span class="chip-dot dot-bank"></span> Banks <span class="chip-count">4</span></button>
  <button class="chip"><span class="chip-dot dot-gov"></span> Government <span class="chip-count">7</span></button>
  <button class="chip"><span class="chip-dot dot-self"></span> Self <span class="chip-count">3</span></button>
  <button class="chip"><span class="chip-dot dot-unknown"></span> Unknown <span class="chip-count">61</span></button>
</div>
```

- Active chip flips to `background: var(--color-text); color: var(--color-bg)`
- Inactive chips have the small colored dot (per-type) + label + count
- Count chip styled as `font-family: var(--font-mono); font-size: 10px; padding: 1px 5px; border-radius: 8px; background: var(--color-surface-2); color: var(--color-text-muted)`. On active chip, count chip background flips to `rgba(255,255,255,0.15)` with `color: var(--color-bg)`

### Card composition (cards view)

```html
<div class="cp-card">
  <div class="cp-head">
    <div class="avatar sm" style="background: <brand-gradient>">A</div>
    <div class="cp-name">Albert Heijn</div>
    <span class="type-chip t-merchant">Merchant</span>
  </div>
  <div class="cp-stats">
    <div><div class="cp-stat-label">12 mo</div><div class="cp-stat-value">− € 4 218</div></div>
    <div><div class="cp-stat-label">Avg / mo</div><div class="cp-stat-value">€ 352</div></div>
  </div>
  <div class="cp-spark"><span style="height: 60%"></span>...12 bars...</div>
  <div class="cp-recent"><span class="desc">23 May · Pickup boodschappen</span><span class="amt">− € 67.40</span></div>
</div>
```

Sparkline: `height: 28px; display: flex; align-items: flex-end; gap: 2px; padding-top: 6px; border-top: 1px solid var(--color-border)`. Bars are
`flex: 1; background: var(--color-blue); border-radius: 2px 2px 0 0; opacity: 0.6`. Last bar gets `opacity: 1` for recency emphasis. Personal-type
incoming transfers flip the bar color to `var(--color-emerald)`.

### Unknown card variant

When the counterparty type is `unknown`, the card switches to a
dashed-border treatment with a built-in "Label this counterparty"
CTA in place of the recent-activity line:

```css
.cp-card.unknown {
  border-style: dashed; background: var(--color-bg-subtle);
}
.cp-card.unknown .cp-stat-value { color: var(--color-text-muted); }
```

CTA button: `background: transparent; border: 1px dashed var(--color-text-muted); color: var(--color-text); border-radius: 4px; padding: 6px; font-size: var(--text-xs)`. Copy: `❋ Label this counterparty`. Click routes to
`/counterparties/triage` with this unknown queued first — does NOT open
a per-row modal or inline editor (sketch 010 winner is dedicated triage,
not contextual modal).

### Self-account row treatment

Self-account counterparties appear in the list but get a softer
treatment — they're surfaced "for completeness" but routed away:

- Card: shows `Routing only` instead of a money total; primary action
  is `Open account view →` (button styled with `background: var(--color-primary); color: var(--color-primary-text)`)
- List row: `total` cell shows `routing only` in muted text + sub-line
  `no spend / no income` in faint; action is `Open account →`

### Page-head & toolbar

```html
<div class="page-head">
  <h1 class="page-title">Counterparties</h1>
  <div class="page-sub"><strong>328</strong> entities · <strong>61</strong> need identification</div>
</div>

<div class="toolbar">
  <div class="search-box">⌕ <input placeholder="Search by name, alias, or IBAN…" /> <kbd>/</kbd></div>
  <div class="filter-chips">...</div>
  <div class="view-toggle">...</div>
  <span class="sort-link">Sort: <strong>Total 12mo ↓</strong></span>
</div>
```

The "61 need identification" copy in the page-sub is **load-bearing** —
it nudges the user toward triage every time they land on the index.
Clickable; routes to `/counterparties/triage`.

### Triage page composition (sketch 010 winner B)

`/counterparties/triage` — a dedicated, focused queue. One unknown at a
time, big card, full focus.

```html
<div class="triage-shell"> <!-- max-width: 760px; margin: 0 auto -->
  <div class="page-head">Triage unknown counterparties</div>

  <div class="progress-bar">
    23 of 61 · [▓▓▓▓▓░░░░░] · 38 % · ~15 min remaining
  </div>

  <div class="triage-card">
    <!-- big card: border, shadow-md, 28px padding, radius-xl -->
    <div class="triage-head">
      <div class="avatar lg unknown">?</div>
      <div>
        <div class="triage-iban">NL · ·· INGB ···· ···· 47</div>
        <div class="triage-meta">
          [3 transactions] [€ 142.00 total] [last seen 19 May]
        </div>
      </div>
    </div>

    <div class="suggestion"> <!-- emerald banner -->
      ✨ Looks like <strong>Ziggo</strong> — confidence high
      <small>all 3 transactions use the Mollie iDEAL processor on the
      same IBAN; Ziggo uses Mollie for most NL collections</small>
      [No, not Ziggo] [Yes, link to Ziggo ↵]
    </div>

    <div class="triage-section">
      <label>Recent transactions on this IBAN</label>
      <!-- 3 dashed-bordered rows showing the actual transactions -->
    </div>

    <div class="triage-section">
      <label>Or label manually</label>
      <input placeholder="Display name…" />
      <select>Merchant / Personal / Bank / Government</select>
    </div>

    <div class="triage-actions">
      [⊘ Mark as ignored] [↷ Skip for now]
      [Y yes · N no · S skip · → next] [Next ▸]
    </div>
  </div>

  <div>↑ Previous unknown · 38 already labeled · 61 to go</div>
</div>
```

### Triage page key decisions

- **Single-card focus** at `max-width: 760px` centered. The narrow
  width is deliberate — it makes the kbd shortcuts feel essential
  rather than optional (no temptation to mouse around).
- **Confidence-based suggestion banner** on `var(--color-emerald-bg)`
  with `border: 1px solid color-mix(in srgb, var(--color-emerald) 24%,
  transparent)`. The reasoning sub-line ("Mollie iDEAL processor on
  the same IBAN; Ziggo uses Mollie for most NL collections") is
  load-bearing — it builds trust in the suggestion engine.
- **Progress bar at top** — `var(--color-emerald)` fill on
  `var(--color-surface)` track. The "~15 min remaining at this pace"
  estimate is the only progress label users actually care about; the
  raw `23 of 61` is on the left for absolute reference.
- **Keyboard shortcut hint row** in the action footer:
  `<kbd>Y</kbd> yes · <kbd>N</kbd> no · <kbd>S</kbd> skip · <kbd>→</kbd> next`.
  kbd-chip style: `font-family: var(--font-mono); font-size: 10px; padding: 1px 5px; border-radius: 3px; background: var(--color-surface-2); color: var(--color-text-faint); border: 1px solid var(--color-border)`.
- **Two escape valves** below the actions: `⊘ Mark as ignored`
  (permanent — this counterparty stays unknown forever; useful for
  one-off Mollie processors that won't recur) and `↷ Skip for now`
  (resurfaces in the next triage session).
- **Sidebar Triage item** (already exists from Phase 16.1's
  transaction-level triage) shows the combined count with amber badge:
  `Triage [61]` (badge styled `background: var(--color-amber-bg); color: var(--color-amber); font-weight: 600` when count > 0). The
  combined badge sums transaction-level + counterparty-level pending
  items.

### Manual-label fallback inside the triage card

When the suggestion is wrong, the user can drop down to the
`Or label manually` section: a `Display name` input + a `Type` select
(4 options — Merchant / Personal / Bank / Government; Self isn't
user-creatable since it's derived from IBAN-matches-user-account).

### Entry points to triage

`/counterparties/triage` is reachable from:

1. Sidebar nav `Triage` item (with combined-count amber badge)
2. The page-sub copy on `/counterparties` (`61 need identification` →
   clickable)
3. The Unknown filter chip on `/counterparties` (filtering to Unknown
   shows a banner suggesting `Open in triage →`)
4. The "Label this counterparty" CTA on any Unknown card in the cards
   view (queues that specific unknown first)
5. The "Label this" hover button on any Unknown row in the list view
   (same behavior as #4)

**The sketch-010 modal pattern and inline-editor pattern were both
rejected in favor of the dedicated triage page** — every entry point
routes there.

## What to Avoid

- **Ledger-style table with bulk-select checkboxes** (sketch 009
  variant C) — too power-user, encourages destructive bulk-set-category
  before the user actually knows what these counterparties are. If bulk
  ops are needed later, they belong in a Dev Mode action, not on the
  user-facing index.
- **Contextual modal from a transaction row** (sketch 010 variant A) —
  contextual labeling sounds good in theory but spawns too many
  half-finished labels and makes the user's transaction-review flow
  feel like data-entry. Single dedicated path wins.
- **Inline editor on the counterparty index** (sketch 010 variant C) —
  similar problem: mixes "browsing" with "labeling." Better to make
  triage a deliberate action (open triage, do 10 at a time, close).
- **Hiding the Unknown filter chip behind a "More filters" affordance** —
  Unknowns are the primary triage entry point; the chip must be visible
  on the toolbar.
- **Suggestion banners without reasoning** — "Looks like Ziggo" alone
  feels arbitrary. The sub-line explaining *why* (Mollie + IBAN
  pattern) is what makes the user click Yes with confidence.

## Origin

Synthesized from sketches: 009, 010
Source files available in: `sources/009-phase-17-counterparty-index/`,
`sources/010-phase-17-identify-unknown-flow/`
