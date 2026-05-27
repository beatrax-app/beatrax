# Import Preview & Categorization

The import-preview row is the densest cell in the app — it has to
carry four jobs at once without becoming noise: source/destination
context (date · counterparty · funding source · amount), a payment-
type signal (PIN vs online vs transfer vs direct-debit vs cash), an
inline rename affordance for cryptic merchant strings, and a
confirm/clear interaction over pre-discovered categories.

## Design Decisions

### Column order (sketch 004, variant D)

`[Type chip] [Date] [Counterparty] [Funding source] [Category] [Amount]`

The **Type** column is new and leading. Putting payment-type first
makes vertical scanning of the table immediately reveal patterns
("everything here is PIN except this one direct-debit") without the
user having to track sideways to the right edge of the row.

### Payment-type chip (sketch 004, variant D)

Single-column chip combining a glyph + word — both signals together.

- **Glyphs:** ⛁ PIN · ⌘ Online · ↔ Transfer · ⤓ Direct debit · €
  Cash. Single character, easy to scan vertically.
- **Token mapping:**
  - PIN — `#7c3aed` violet on `rgba(124,58,237,0.10)` bg
  - Online — `var(--color-blue)` on `var(--color-blue-bg)`
  - Transfer — `var(--color-text-muted)` on `var(--color-surface-2)`
  - Direct debit — `var(--color-amber)` on `var(--color-amber-bg)`
  - Cash — `#a16207` dark amber on `rgba(161,98,7,0.10)`
- **Shape:** pill (`border-radius: var(--radius-full)`),
  `padding: 2px 8px`, `font-size: var(--text-xs)`, `font-weight: 500`.
- **Anchor:** legend strip above the table acts as a discovery
  affordance (visible on first-import / preview surfaces, hidden on
  the steady-state `/transactions` list once the user has
  internalised the chips).

**Why D won over A's leading-glyph-only or B's trailing-chip or C's
edge-stripe:** Glyph-only (A) leans too hard on the legend.
Trailing-chip (B) crowds the counterparty cell. Edge-stripe (C) is
color-only encoding — fragile for accessibility and useless for
non-technical users who don't know the convention. Leading chip with
glyph+word in its own column is unambiguous and scannable.

### Inline rename for fallback-description rows (sketch 004D)

- Rows whose `counterparty_name` is null fall back to rendering the
  raw description text (e.g. `BCK*SHELL PIETER NIEUW`).
- This fallback text renders in italic muted styling (`font-style:
  italic; color: var(--color-text-muted);`).
- **The italic text is itself the click target** — no separate
  pencil button. The italic styling acts as the invitation: "this
  isn't a real name yet; click to give it one".
- Click opens a popover with:
  - Input pre-filled empty (placeholder shows the raw description).
  - Checkbox "Remember 'BCK*SHELL PIETER NIEUW' as this name" (on
    by default) — seeds a learning rule for future imports.
  - `[Cancel] [Save]` actions, save is primary.
- Popover is anchored to the clicked element with a 6px gap.

**Why click-the-italic-text wins over hover-pencil or modifier-key:**
Pencil-on-hover is conventional but easy to miss. ⌥-click is power-
user-y and bad for non-technical users. The italic text already
signals "not good enough yet" — making it the click target makes the
affordance feel invitational, not bolted on.

### Funding source column (sketch 004D)

Monospace pill-tag showing the masked account IBAN (e.g.
`NL91 ASNB · 4321`). Pill background `var(--color-surface-2)`,
`font-family: var(--font-mono)`, `font-size: var(--text-xs)`. Quiet
so it disappears when the user has only one account, but legible
enough to scan when they have multiple. Header column rename
"Source → Funding source" is locked from Phase 16 commit `7e37921`.

### Category cell — three states + bulk-confirm (sketch 004D)

The category column has three render states. Auto-categorization
pre-fills a guess on every row it can; the user confirms / clears /
replaces per row, with a bulk-confirm shortcut.

**State 1 — Auto-suggested (the pipeline guessed):**
- Chip with dashed border + italic font + muted color (`var(--color-
  text-muted)`). Background transparent. Hover over the row reveals
  two quick-action buttons next to the chip:
  - **✓ Confirm** — hover state turns emerald. Click commits the
    category.
  - **× Clear** — hover state turns rose. Click drops to "Pick a
    category" (state 3).
- Tooltip on the chip: "Auto-suggested — click ✓ to confirm".

**State 2 — Confirmed (user-validated or manually-set):**
- Chip with solid `var(--color-surface-2)` background, transparent
  border, normal font weight, regular text color.
- Tiny emerald `✓` prefix (`::before` content).
- No hover quick-actions (still clickable to re-edit via picker
  when implemented).

**State 3 — Uncategorized:**
- Ghost button "**+** Pick a category" — italic faint text, no
  border by default. Hover: dashed border appears + text becomes
  muted (not faint). Click opens picker.

**Bulk action — "Confirm all suggestions":**
- Lives in the legend bar at the top-right of the table.
- Button shape: `pill-btn` style with a leading `✓` and a trailing
  count chip (emerald-bg, count of currently-auto rows).
- Confirms every row currently in state 1. Auto-hides when count
  reaches zero.

**Live counter — footer row:**
- "**1 confirmed**, 5 auto-suggested, 2 uncategorized" — updates as
  the user confirms/clears.
- Lets the user see import-readiness state at a glance.

## CSS Patterns

### Payment-type chips

```css
.ptype-chip {
  padding: 2px 8px; border-radius: var(--radius-full);
  font-size: var(--text-xs); font-weight: 500;
  display: inline-flex; align-items: center; gap: 4px;
}
.ptype-chip.pin      { background: rgba(124,58,237,0.10); color: #7c3aed; }
.ptype-chip.online   { background: var(--color-blue-bg);  color: var(--color-blue); }
.ptype-chip.transfer { background: var(--color-surface-2); color: var(--color-text-muted); }
.ptype-chip.dd       { background: var(--color-amber-bg); color: var(--color-amber); }
.ptype-chip.cash     { background: rgba(161,98,7,0.10);   color: #a16207; }
```

### Funding-source pill

```css
.funding-tag {
  display: inline-block; padding: 2px 8px;
  background: var(--color-surface-2); border-radius: var(--radius-sm);
  color: var(--color-text);
}
.funding { font-family: var(--font-mono); font-size: var(--text-xs);
  color: var(--color-text-muted); letter-spacing: 0.02em; }
```

### Italic-fallback counterparty + click affordance

```css
.desc-fallback {
  font-style: italic; color: var(--color-text-muted);
  cursor: text;
}
.desc-fallback:hover {
  background: var(--color-surface-2);
  border-radius: var(--radius-sm);
  box-shadow: 0 0 0 2px var(--color-surface-2);
}
.desc-fallback::after {
  content: '  ✎';
  opacity: 0;
  font-size: 12px;
  color: var(--color-text-muted);
  font-style: normal;
  transition: opacity 120ms ease;
}
tr:hover .desc-fallback::after { opacity: 1; }
```

### Category cell — three states

```css
.cat-cell { display: inline-flex; align-items: center; gap: 6px; font-size: var(--text-xs); }

.cat-chip {
  display: inline-flex; align-items: center; gap: 4px;
  padding: 3px 8px; border-radius: var(--radius-sm);
  background: var(--color-surface-2); color: var(--color-text);
  font-size: var(--text-xs); cursor: pointer;
  transition: var(--tx-quick);
}

.cat-chip.auto {
  background: transparent; color: var(--color-text-muted);
  border: 1px dashed var(--color-border-strong);
  font-style: italic;
}
.cat-chip.auto:hover { background: var(--color-surface-2); color: var(--color-text); }

.cat-chip.confirmed {
  background: var(--color-surface-2); color: var(--color-text);
  border: 1px solid transparent; font-style: normal;
}
.cat-chip.confirmed::before {
  content: '✓'; color: var(--color-emerald);
  font-size: 11px; margin-right: 1px;
}

.cat-actions { display: inline-flex; gap: 2px; opacity: 0; transition: opacity 120ms ease; }
tr:hover .cat-actions { opacity: 1; }
.cat-action-btn {
  background: var(--color-surface); border: 1px solid var(--color-border);
  border-radius: var(--radius-sm); cursor: pointer;
  width: 22px; height: 22px;
  display: inline-grid; place-items: center;
  color: var(--color-text-muted); font-size: 12px;
  transition: var(--tx-quick); padding: 0;
}
.cat-action-btn.confirm:hover { color: var(--color-emerald); border-color: var(--color-emerald); }
.cat-action-btn.clear:hover   { color: var(--color-rose);    border-color: var(--color-rose); }

.cat-uncategorized {
  display: inline-flex; align-items: center; gap: 4px;
  color: var(--color-text-faint); font-style: italic;
  font-size: var(--text-xs);
  background: transparent; border: 1px dashed transparent;
  padding: 3px 8px; border-radius: var(--radius-sm);
  cursor: pointer; transition: var(--tx-quick);
}
.cat-uncategorized:hover {
  color: var(--color-text-muted);
  border-color: var(--color-border);
  background: var(--color-surface-2);
}
```

### Rename popover

```css
.popover {
  position: absolute; z-index: 50;
  background: var(--color-surface);
  border: 1px solid var(--color-border-strong);
  border-radius: var(--radius-md);
  box-shadow: var(--shadow-lg);
  padding: 14px;
  min-width: 320px;
  font-size: var(--text-sm);
}
.popover .field-label {
  display: block; font-size: var(--text-xs);
  color: var(--color-text-muted); margin-bottom: 4px;
}
.popover .remember {
  margin: 12px 0 4px;
  display: flex; align-items: flex-start; gap: 8px;
  font-size: var(--text-sm); color: var(--color-text);
  cursor: pointer;
}
.popover .remember .sub {
  font-size: var(--text-xs); color: var(--color-text-muted);
  display: block;
}
.popover .pop-actions {
  display: flex; justify-content: flex-end; gap: 6px; margin-top: 10px;
}
```

## HTML Structures

### Row with auto-suggested category

```html
<tr data-suggested="Groceries">
  <td><span class="ptype-chip pin">⛁ PIN</span></td>
  <td class="date">May 20</td>
  <td>
    <div class="cp-cell">
      <span class="cp-name">Albert Heijn 1245</span>
    </div>
  </td>
  <td><span class="funding-tag funding">NL91 ASNB · 4321</span></td>
  <td>
    <div class="cat-cell">
      <span class="cat-chip auto" title="Auto-suggested — click ✓ to confirm">Groceries</span>
      <span class="cat-actions">
        <button class="cat-action-btn confirm" title="Confirm">✓</button>
        <button class="cat-action-btn clear"   title="Clear">×</button>
      </span>
    </div>
  </td>
  <td class="num amt-neg">−€ 37,82</td>
</tr>
```

### Row with fallback counterparty + uncategorized

```html
<tr>
  <td><span class="ptype-chip pin">⛁ PIN</span></td>
  <td class="date">May 19</td>
  <td>
    <div class="cp-cell">
      <span class="desc-fallback cp-name" tabindex="0">BCK*SHELL PIETER NIEUW</span>
    </div>
  </td>
  <td><span class="funding-tag funding">NL91 ASNB · 4321</span></td>
  <td>
    <div class="cat-cell">
      <button class="cat-uncategorized" title="Pick a category">
        <span class="plus">+</span> Pick a category
      </button>
    </div>
  </td>
  <td class="num amt-neg">−€ 42,10</td>
</tr>
```

### Legend bar with bulk-confirm

```html
<div class="legend">
  <span style="font-weight:500;">Payment type:</span>
  <span class="legend-item"><span class="ptype-chip pin">⛁ PIN</span></span>
  <span class="legend-item"><span class="ptype-chip online">⌘ Online</span></span>
  <span class="legend-item"><span class="ptype-chip transfer">↔ Transfer</span></span>
  <span class="legend-item"><span class="ptype-chip dd">⤓ Direct debit</span></span>
  <span class="legend-item"><span class="ptype-chip cash">€ Cash</span></span>
  <span style="flex:1"></span>
  <button class="bulk-cat-action" onclick="confirmAllSuggestions()">
    <span>✓</span> Confirm all suggestions <span class="count">5</span>
  </button>
</div>
```

### Live counter (footer)

```html
<div class="preview-foot">
  <span>
    7 rows shown · 30 more ·
    <span id="confirmed-line">
      <strong>1 confirmed</strong>, 5 auto-suggested, 2 uncategorized
    </span>
  </span>
  <div class="pf-actions">
    <button class="btn ghost">Cancel</button>
    <button class="btn primary">Import 37 transactions →</button>
  </div>
</div>
```

### Rename popover

```html
<div class="popover" id="rename-popover">
  <label class="field-label">Rename this counterparty</label>
  <input type="text" placeholder="Shell — Pieter Nieuwlandstraat">
  <label class="remember">
    <input type="checkbox" checked>
    <span>
      Remember "BCK*SHELL PIETER NIEUW" as this name
      <span class="sub">Future imports get the same name automatically.</span>
    </span>
  </label>
  <div class="pop-actions">
    <button class="pop-btn ghost">Cancel</button>
    <button class="pop-btn primary">Save</button>
  </div>
</div>
```

## What to Avoid

- **Don't encode payment type with only color** (sketch 004 variant
  C tried left-edge stripe). Color-only encoding is fragile for
  accessibility and useless for users who don't know the
  convention.
- **Don't add a dedicated pencil button next to the fallback text**
  (004 variant A). It works, but it's a separate affordance the
  user has to discover — the italic text *is* the invitation.
- **Don't put the payment-type chip after the counterparty** (004
  variant B). The chip-then-cell rhythm broke when the table got
  busy; leading the row with the chip lets vertical scanning work.
- **Don't auto-confirm suggested categories on the user's behalf.**
  The whole point of the dashed-italic styling is to mark the
  category as *tentative* until the human says yes. Auto-confirm
  would silently lock in mistakes.
- **Don't fire the "remember for future imports" learning rule on
  every save by default in invisible mode.** The checkbox needs to
  stay visible so the user knows what they're opting into.

## Origin

Synthesized from sketch: 004 (preview row affordances).

Source files: `sources/004-phase-16-1-preview-row/`.

Touchpoints in existing code:
- The italic-fallback styling already exists on the Counterparty
  cell from Phase 16 commit `442f139`.
- The "Funding source" column header rename is from Phase 16 commit
  `7e37921`.
- The payment-type chip, the click-rename popover, the category
  three-state cell, the bulk-confirm action, and the live counter
  are all new in Phase 16.1.
