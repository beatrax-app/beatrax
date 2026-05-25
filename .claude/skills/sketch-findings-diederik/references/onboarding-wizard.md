# Onboarding Wizard

The first-run wizard is the very first surface a non-technical user
sees after signup. The chrome here propagates to every step that
follows — Welcome, Connect bank (ASN), Connect card (ICS), optional
email OAuth, first import preview, Done.

## Design Decisions

### Chrome — Centered card on neutral wash (sketch 002, variant D)

- **Card width:** `max-width: 620px`. Wider than a stock Stripe-
  Checkout card so the three-row "what's coming" preview breathes;
  narrower than a Notion-style 720px so the eye doesn't have to
  track across.
- **Card padding:** `44px 48px 32px`.
- **Background:** card on a `--color-bg-subtle` page wash
  (`#f8fafc` light / `#0b1220` dark) so the surface separates from
  the chrome.
- **Top bar:** brand (mark + "diederik") on the left, progress dots
  in the middle showing "Step N of M", "Resume later →" ghost
  button on the right.
- **Progress dots:** 8px circles for upcoming, 8px emerald circles
  for done, 24px-wide rounded rectangle for current step. Plus
  inline "Step 2 of 6" label.
- **Bottom of card:** thin divider above the `wiz-actions` row which
  is right-aligned `[Skip this step] [Continue →]` — primary
  filled, skip as a ghost button.
- **Footer of page:** small privacy pill on the left
  ("● Your data stays on this computer") + "Need help?" quiet link
  on the right.

**Why it won (over centered-card-only A, split-context B, single-
column-calm C):** A's chrome is the calmest base, but its 520px card
felt cramped with multi-line content. C's emoji rows worked better
than A's numbered timeline for first-impression clarity. D
synthesised: A's chrome + 620px (wider) + C's emoji-glyph content
rows.

### Welcome step body (sketch 002, variant D)

Inside the 620px card:

- **Eyebrow:** "Welcome" (uppercase, letter-spaced, muted).
- **H1:** "Let's get diederik to know your money." — `--text-3xl`,
  `font-weight: 600`, `letter-spacing: -0.02em`.
- **Lede:** one paragraph (`--text-lg`, line-height 1.55, muted
  colour) setting the 5-minute expectation and the "nothing leaves
  this computer" promise.
- **Tagline:** "Here's what we'll set up:" (`--text-xs`, faint).
- **Three glyph rows:** 🏦 Your bank (ASN) · 💳 Your credit card
  (ICS) · ✉️ Receipts from email (with `— optional` suffix in faint
  text). Each row is a 44×44 muted-bg glyph tile + title + one-line
  description.

### Connector step body (sketch 003, variant C)

For every "connect a data source" step (bank, card, optional email):

- **Eyebrow:** glyph + step label, e.g. "🏦 Step 3 — Your bank (ASN)".
- **H1:** action-oriented, e.g. "Grab a statement, then drop it below".
- **Lede:** one sentence sets the 1-minute expectation.
- **Mini-steps tile row (the key innovation):** four equal-width
  tiles in a grid showing the *whole journey at a glance*. Each
  tile: glyph (24px) + label + sub-label. States: muted (upcoming),
  emerald-bg (done), primary-bg (current).
  - Example for ASN: 🔐 Log in (mijn.asnbank.nl) · 📑 Open
    afschriften (Top-right menu) · 📅 Pick a range (Last 90 days) ·
    ⬇️ Download (CAMT.053 or CSV).
- **Format-chip row:** "Got it as:" label + chips for the file
  formats. Quiet emerald-bg "recommended" badge on the preferred
  format (e.g. CAMT.053). User can change selection; sketch shows
  selected state with darker border + filled background.
- **Drop zone (always visible):** dashed-border rounded box, 28px
  glyph (📥), "Drop your statement file here" lead, "or browse for
  a file" sublink. On hover/drag: solid border, surface-2 bg.
- **Bottom of card:** same `wiz-actions` row as the shell —
  `[Skip this step] [Continue →]` (continue stays disabled until a
  file lands).

**Why C won (over accordion A, side-by-side B):** A hid the drop
zone behind a multi-step accordion — the user couldn't see where
they were headed. B widened the card to 1040px which broke the
shell's width consistency. C kept the same card width as the rest of
the wizard, made the whole journey visible at once via the mini-
tile row, and kept the drop zone always reachable at the bottom.
The mini-tile row's only weakness (visual busyness of four glyphs in
a row) is acceptable given the orientation benefit.

### Reuse across steps

The connector-step shape (mini-tiles + format chips + drop zone)
applies *unchanged* to:

- **Bank statement upload** (ASN CSV / CAMT.053 / MT940) — example
  in the sketch.
- **Credit card statement upload** (ICS PDF — single format, so the
  format-chips row collapses to a single non-interactive chip).
- **Optional email OAuth** (Gmail / Microsoft Graph) — the drop
  zone is swapped for an "Authorize with [provider]" button; the
  mini-tiles describe the consent screen steps instead.

## CSS Patterns

### Shell — page + top bar + body + footer

```css
.wiz-page {
  min-height: calc(100vh - 48px);
  background: var(--color-bg-subtle);
  display: flex; flex-direction: column;
}
.wiz-top {
  padding: 24px 28px;
  display: flex; justify-content: space-between; align-items: center;
}
.wiz-body { flex: 1; display: grid; place-items: center; padding: 24px; }
.wiz-card {
  width: 100%; max-width: 620px;
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-xl);
  box-shadow: var(--shadow-md);
  padding: 44px 48px 32px;
}
.wiz-actions {
  display: flex; gap: 8px; justify-content: flex-end; align-items: center;
  padding-top: 8px;
}
.wiz-footer {
  padding: 16px 28px 24px;
  display: flex; justify-content: space-between;
  color: var(--color-text-faint); font-size: var(--text-xs);
}
```

### Progress dots

```css
.wiz-dots { display: flex; gap: 8px; align-items: center;
  color: var(--color-text-muted); font-size: var(--text-xs); }
.wiz-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--color-border); }
.wiz-dot.done { background: var(--color-emerald); }
.wiz-dot.now { background: var(--color-primary); width: 24px; border-radius: 4px; }
```

### Privacy pill

```css
.privacy-pill {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 4px 10px;
  background: var(--color-surface-2); border: 1px solid var(--color-border);
  border-radius: var(--radius-full);
  color: var(--color-text-muted); font-size: var(--text-xs);
}
.privacy-pill .dot { width: 6px; height: 6px; border-radius: 50%; background: var(--color-emerald); }
```

### Welcome-step glyph rows

```css
.vd-rows { list-style: none; padding: 0; margin: 8px 0 0;
  display: flex; flex-direction: column; gap: 22px; }
.vd-rows li { display: flex; gap: 18px; align-items: flex-start; }
.vd-glyph {
  flex: 0 0 44px; height: 44px;
  display: grid; place-items: center;
  font-size: 26px; line-height: 1;
  background: var(--color-surface-2); border-radius: var(--radius-lg);
}
.vd-row-title { font-weight: 600; font-size: var(--text-md); line-height: 1.4; }
.vd-row-title .optional {
  font-weight: 400; color: var(--color-text-faint);
  font-size: var(--text-sm); margin-left: 6px;
}
.vd-row-desc {
  color: var(--color-text-muted); font-size: var(--text-sm);
  line-height: 1.55; margin-top: 2px;
}
```

### Connector mini-tile row + format chips + drop zone

```css
.mini-steps {
  display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px;
  margin: 16px 0 24px;
}
.mini {
  padding: 14px 12px; text-align: center;
  background: var(--color-surface-2); border-radius: var(--radius-md);
  font-size: var(--text-sm); color: var(--color-text-muted);
}
.mini .glyph { display: block; font-size: 24px; line-height: 1; margin-bottom: 8px; }
.mini .label { font-weight: 500; color: var(--color-text); }
.mini .sub   { font-size: var(--text-xs); color: var(--color-text-muted); margin-top: 2px; }
.mini.done   { background: var(--color-emerald-bg); }
.mini.done .label { color: var(--color-emerald); }
.mini.now    { background: var(--color-primary); }
.mini.now .label, .mini.now .sub { color: var(--color-primary-text); }

.format-chips { display: flex; gap: 8px; flex-wrap: wrap; }
.format-chip {
  display: inline-flex; align-items: center; gap: 8px;
  padding: 8px 14px;
  background: var(--color-surface); border: 1px solid var(--color-border);
  border-radius: var(--radius-md); cursor: pointer;
  font-size: var(--text-sm); color: var(--color-text);
  transition: var(--tx-quick);
}
.format-chip.selected {
  border-color: var(--color-text); background: var(--color-surface-2);
  font-weight: 500;
}
.format-chip .badge {
  background: var(--color-emerald-bg); color: var(--color-emerald);
  font-size: var(--text-xs); padding: 1px 6px; border-radius: var(--radius-sm);
  font-weight: 500;
}

.drop-zone {
  border: 1.5px dashed var(--color-border-strong);
  border-radius: var(--radius-lg);
  padding: 28px 20px; text-align: center;
  background: var(--color-bg-subtle);
  color: var(--color-text-muted);
  cursor: pointer; transition: var(--tx-quick);
}
.drop-zone:hover, .drop-zone.dragover {
  border-color: var(--color-text); background: var(--color-surface-2);
  color: var(--color-text);
}
```

## HTML Structures

### Shell skeleton

```html
<div class="wiz-page">
  <header class="wiz-top">
    <div class="brand-row">
      <div class="brand-mark">d</div>
      <div class="brand-name">diederik</div>
    </div>
    <div class="wiz-dots" aria-label="Step 3 of 6">
      <span class="wiz-dot done"></span>
      <span class="wiz-dot done"></span>
      <span class="wiz-dot now"></span>
      <span class="wiz-dot"></span>
      <span class="wiz-dot"></span>
      <span class="wiz-dot"></span>
      <span style="margin-left:8px;">Step 3 of 6 · {Step name}</span>
    </div>
    <button class="btn btn-ghost">Resume later →</button>
  </header>

  <main class="wiz-body">
    <article class="wiz-card">
      <!-- step body -->
      <div class="wiz-actions">
        <button class="btn btn-ghost">Skip this step</button>
        <button class="btn btn-primary">Continue →</button>
      </div>
    </article>
  </main>

  <footer class="wiz-footer">
    <span class="privacy-pill"><span class="dot"></span> Your data stays on this computer</span>
    <a href="#" class="link-quiet">Need help?</a>
  </footer>
</div>
```

### Connector body skeleton

```html
<p class="step-eyebrow">🏦 Step 3 — Your bank (ASN)</p>
<h1 class="step-h1">Grab a statement, then drop it below</h1>
<p class="step-lede">Four small steps. You'll be in your bank's site for about a minute.</p>

<ol class="mini-steps">
  <li class="mini done"><span class="glyph">🔐</span><span class="label">Log in</span><span class="sub">mijn.asnbank.nl</span></li>
  <li class="mini done"><span class="glyph">📑</span><span class="label">Open afschriften</span><span class="sub">Top-right menu</span></li>
  <li class="mini now"><span class="glyph">📅</span><span class="label">Pick a range</span><span class="sub">Last 90 days</span></li>
  <li class="mini"><span class="glyph">⬇️</span><span class="label">Download</span><span class="sub">CAMT.053 or CSV</span></li>
</ol>

<div class="vc-format-row">
  <span>Got it as:</span>
  <div class="format-chips">
    <span class="format-chip selected">CAMT.053 <span class="badge">recommended</span></span>
    <span class="format-chip">CSV</span>
    <span class="format-chip">MT940</span>
  </div>
</div>

<div class="drop-zone">
  <div class="glyph">📥</div>
  <div class="lead">Drop your statement file here</div>
  <div class="sub">or <a href="#" class="browse">browse for a file</a></div>
</div>
```

## What to Avoid

- **Don't widen the wizard card per step** (sketch 003 variant B
  tried 1040px for side-by-side guide + drop zone). Breaks the
  consistent-shell promise and the user notices the card "jumping".
  Acceptable single exception: the first-import-preview step
  relaxes to ~1120px to fit the preview table.
- **Don't hide the drop zone behind a multi-step accordion**
  (003 variant A). Users can't see where they're headed; reduces
  trust that this is a small task.
- **Don't put the primary CTA in the footer bar** (002 variant C).
  Users have to scroll to reach it. Inside the card, bottom-right,
  is the right place.
- **Don't use a top progress bar without a numeric "Step N of M"
  label.** The thin bar alone (002 variant C) doesn't convey
  how many steps remain.
- **Don't use a numbered timeline of past+current+future actions
  inside the welcome step** (002 variant A). It implies the user
  has to read the journey before doing it. The glyph rows (002D /
  003C) convey the same orientation faster.

## Origin

Synthesized from sketches: 002 (wizard shell + welcome) + 003
(connect a data source).

Source files: `sources/002-phase-16-1-wizard-shell/`,
`sources/003-phase-16-1-connect-source/`.
