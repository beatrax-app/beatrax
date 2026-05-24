# Component Library

Cross-cutting primitives used across every Dev Console surface, plus
the main app where applicable. The token names match
`sources/themes/default.css` exactly — when wiring to Tailwind v4 in
the real app, port these into your `@theme` block under
`resources/css/app.css`.

## Color Tokens

### Surfaces

| Token | Light | Dark | Use |
|-------|-------|------|-----|
| `--color-bg` | `#ffffff` | `#020617` (slate-950) | Page background, html |
| `--color-bg-subtle` | `#f8fafc` (slate-50) | `#0b1220` | Sidebar bg, card-header bg, table thead |
| `--color-surface` | `#ffffff` | `#0b1220` | Card body, kpi tile |
| `--color-surface-2` | `#f1f5f9` (slate-100) | `#1e293b` (slate-800) | Hover, active nav row, chip bg |
| `--color-surface-raised` | `#ffffff` | `#0f172a` (slate-900) | Modal, palette |
| `--color-border` | `#e2e8f0` (slate-200) | `#1e293b` | Default border |
| `--color-border-strong` | `#cbd5e1` (slate-300) | `#334155` (slate-700) | Hover border, dashed dev block |

### Text

| Token | Light | Dark | Use |
|-------|-------|------|-----|
| `--color-text` | `#0f172a` (slate-900) | `#f1f5f9` (slate-100) | Body text |
| `--color-text-muted` | `#64748b` (slate-500) | `#94a3b8` (slate-400) | Secondary text, section labels |
| `--color-text-faint` | `#94a3b8` (slate-400) | `#64748b` | Tertiary, placeholders |
| `--color-text-inverse` | `#f8fafc` | `#0f172a` | Text on primary fill |

### State

| Token | Light | Dark | Used for |
|-------|-------|------|----------|
| `--color-emerald` | `#059669` | `#10b981` | OK, success, alive, positive net |
| `--color-emerald-bg` | `#d1fae5` | `rgba(16,185,129,0.12)` | OK pill bg, dot glow |
| `--color-amber` | `#b45309` | `#f59e0b` | Warn, destructive tier chip |
| `--color-amber-bg` | `#fef3c7` | `rgba(245,158,11,0.12)` | Warn pill bg, dev source chip |
| `--color-rose` | `#be123c` | `#f43f5e` | Fail, error, rose alerts |
| `--color-rose-bg` | `#ffe4e6` | `rgba(244,63,94,0.12)` | Fail pill bg, alert badge |
| `--color-blue` | `#2563eb` | `#60a5fa` | Info, running, links |
| `--color-blue-bg` | `#dbeafe` | `rgba(96,165,250,0.12)` | Info pill bg, action source chip |

Use state colors **only** for state. Don't tint a section header in
emerald to mean "everything's good"; let the absence of warn/error
chips do that job.

## Typography

```css
--font-sans: 'Inter', system-ui, -apple-system, 'Segoe UI', sans-serif;
--font-mono: 'JetBrains Mono', ui-monospace, 'SF Mono', 'Menlo', monospace;

--text-xs: 0.75rem;       /* 12 — footnotes, kbd hints */
--text-sm: 0.8125rem;     /* 13 — dev console body */
--text-base: 0.9375rem;   /* 15 — main app body */
--text-md: 1rem;          /* 16 — emphasis */
--text-lg: 1.125rem;      /* 18 */
--text-xl: 1.375rem;      /* 22 — dev page title */
--text-2xl: 1.75rem;      /* 28 — main page title */
--text-3xl: 2rem;         /* 32 — KPI value */
```

Apply `font-variant-numeric: tabular-nums` to:

- Every KPI value
- Every count / badge / amount
- Every row of a table that contains numbers
- Sparkline labels

The body's `font-feature-settings: 'tnum'` covers most of this, but
mono font fallbacks need the explicit variant too.

## Spacing

```css
--space-1: 4px;   --space-2: 8px;   --space-3: 12px;  --space-4: 16px;
--space-5: 20px;  --space-6: 24px;  --space-8: 32px;  --space-10: 40px;  --space-12: 48px;
```

Density rules:

- **Main app** — section gaps `space-y-12`, card padding `--space-6`
- **Dev console** — section gaps `--space-4` to `--space-6`, card
  padding `--space-3` to `--space-4`, row padding `--space-2`
- **Tables in dev console** — `9px 10px` cell padding (≈ 0.5 × main app)

## Status Pills

```html
<span class="status-pill ok"><span class="dot"></span> Queue worker: alive (4s ago)</span>
<span class="status-pill warn"><span class="dot"></span> Token expired</span>
<span class="status-pill fail"><span class="dot"></span> failed · exit 1</span>
<span class="status-pill muted"><span class="dot"></span> unknown</span>
```

```css
.status-pill {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 2px 8px; border-radius: var(--radius-full);
  font-size: var(--text-xs); font-weight: 500;
}
.status-pill .dot { width: 6px; height: 6px; border-radius: 50%; }
.status-pill.ok    { background: var(--color-emerald-bg); color: var(--color-emerald); }
.status-pill.warn  { background: var(--color-amber-bg);   color: var(--color-amber); }
.status-pill.fail  { background: var(--color-rose-bg);    color: var(--color-rose); }
.status-pill.muted { background: var(--color-surface-2);  color: var(--color-text-muted); }

.status-pill.ok .dot    { background: var(--color-emerald); }
.status-pill.warn .dot  { background: var(--color-amber); }
.status-pill.fail .dot  { background: var(--color-rose); }
.status-pill.muted .dot { background: var(--color-text-faint); }
```

## Tier Chips

For dev-mode-audit rows, run cards, palette dev rows:

```html
<span class="tier safe">safe</span>
<span class="tier destructive">destructive</span>
```

```css
.tier {
  font-size: 10.5px;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  font-weight: 600;
  padding: 2px 6px;
  border-radius: var(--radius-sm);
}
.tier.safe        { background: var(--color-emerald-bg); color: var(--color-emerald); }
.tier.destructive { background: var(--color-rose-bg);    color: var(--color-rose); }
```

Note tier uses **rose** (not amber) for destructive. Amber is reserved
for the palette source chip (`dev · safe`) and warning state.

## Kbd Hints

Used in: sidebar search row, palette footer, header buttons, run-card
shortcuts.

```html
<span class="kbd">⌘K</span>
<span class="kbd">↩</span>
<span class="kbd">esc</span>
<span class="kbd">⇥</span>
```

```css
.kbd {
  display: inline-flex; align-items: center; gap: 1px;
  padding: 1px 5px;
  border: 1px solid var(--color-border);
  border-bottom-width: 2px;
  border-radius: 4px;
  background: var(--color-surface);
  font-family: var(--font-mono);
  font-size: 10px;
  color: var(--color-text-muted);
  line-height: 1.4;
}
```

Always size `10-11 px` — kbd is a hint, never a primary affordance.
Combinations render as two adjacent kbds with no separator: `<span
class="kbd">⌘</span><span class="kbd">K</span>`.

## Dense Tables

For dev-mode audit, queue inspector, schema viewer:

```css
.table { width: 100%; border-collapse: collapse; font-variant-numeric: tabular-nums; }
.table th {
  text-align: left; font-weight: 500;
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--color-text-muted);
  padding: 8px 10px;
  border-bottom: 1px solid var(--color-border);
  background: var(--color-bg-subtle);
}
.table td {
  padding: 9px 10px;
  font-size: var(--text-sm);
  border-bottom: 1px solid var(--color-border);
}
.table tr:hover td { background: var(--color-surface-2); }
.table td.mono { font-family: var(--font-mono); font-size: 12px; }
.table .muted  { color: var(--color-text-muted); }
```

**Right-align** numeric columns (exit code, duration, row counts). Use
`<td style="text-align:right">` or a `.tnum-r` utility. Never
center-align numbers.

## Buttons

```html
<button class="pill-btn">Cancel</button>
<button class="pill-btn primary">▶ Run</button>
<button class="pill-btn danger">Cancel run</button>
<button class="icon-btn">‹</button>
```

```css
.pill-btn {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 6px 12px; border-radius: var(--radius-md);
  border: 1px solid var(--color-border);
  background: var(--color-surface);
  color: var(--color-text);
  font-size: var(--text-sm);
  transition: var(--tx-quick);
}
.pill-btn:hover { background: var(--color-surface-2); border-color: var(--color-border-strong); }
.pill-btn.primary {
  background: var(--color-primary); color: var(--color-primary-text);
  border-color: var(--color-primary);
}
.pill-btn.primary:hover { opacity: 0.92; }
.pill-btn.danger {
  background: var(--color-rose); color: white;
  border-color: var(--color-rose);
}

.icon-btn {
  width: 32px; height: 32px;
  display: grid; place-items: center;
  border: 0; background: transparent;
  border-radius: var(--radius-md);
  color: var(--color-text-muted);
}
.icon-btn:hover { background: var(--color-surface-2); color: var(--color-text); }
```

The "primary" button uses the inverted slate fill (light: slate-900 on
white; dark: slate-100 on slate-950). Don't add a blue primary — the
calm Linear/Notion aesthetic relies on this inversion.

## Switches & Toggles

Used for: Advanced toggle (Dev Mode), boolean arg-form fields.

```css
.switch {
  width: 32px; height: 18px; border-radius: 18px;
  background: var(--color-border-strong);
  position: relative;
  transition: var(--tx-quick);
  cursor: pointer;
  border: 0; padding: 0;
}
.switch::after {
  content: ""; position: absolute; top: 2px; left: 2px;
  width: 14px; height: 14px; border-radius: 50%;
  background: white;
  transition: var(--tx-quick);
}
.switch.on { background: var(--color-emerald); }
.switch.on::after { left: 16px; }
```

The Advanced toggle is **session-scoped** and resets on login + on Dev
Console first-load per session (Phase 16 D-20). Don't persist it.

## Shape & Radii

```css
--radius-sm: 4px;      /* tier chips, kbd, source chips */
--radius-md: 6px;      /* buttons, inputs, sidebar items */
--radius-lg: 8px;      /* cards, tiles */
--radius-xl: 10px;     /* modals, palette */
--radius-full: 9999px; /* status pills, badges */
```

## Shadows

```css
--shadow-xs: 0 1px 1px rgba(15, 23, 42, 0.04);
--shadow-sm: 0 1px 2px rgba(15, 23, 42, 0.06);
--shadow-md: 0 4px 6px -1px rgba(15, 23, 42, 0.08), 0 2px 4px -2px rgba(15, 23, 42, 0.06);
--shadow-lg: 0 14px 30px -6px rgba(15, 23, 42, 0.18), 0 6px 12px -6px rgba(15, 23, 42, 0.10);
--shadow-palette: 0 24px 70px -12px rgba(15, 23, 42, 0.40), 0 8px 20px -8px rgba(15, 23, 42, 0.20);
```

Shadow usage:

- Cards: no shadow (just border). The calm-slate aesthetic avoids drop
  shadows on flat surfaces.
- Modals + palette: `--shadow-palette` (the dramatic one). Justified
  because they overlay the page.
- Hover on raised buttons: optional `--shadow-sm` on hover for the
  primary button only.

## Motion

```css
--ease-smooth: cubic-bezier(0.2, 0.0, 0.0, 1);
--tx-quick: all 120ms var(--ease-smooth);
--tx-base:  all 180ms var(--ease-smooth);
```

- **120ms** — color/bg changes on hover, switch thumb
- **180ms** — modal pop, card expand/collapse
- **No animation longer than 200ms.** Calm UI = fast UI.

## Dark Mode Strategy

`.dark` class on `<html>` (matching the existing `app.blade.php`
pattern in `Modules/Core/Resources/views`). Tokens flip via `.dark
:root` selector in `default.css`. Three exceptions never flip:

1. **Run card output `.run-card-out`** — fixed dark inset
   (`#0b1220`/`#e2e8f0`) regardless of theme
2. **Console pane on `/dev` overview** — same fixed dark inset
3. **`#sketch-tools` toolbar** — always dark, semi-transparent

Everything else inherits via `var(--color-…)`.

## Sparkline (server-rendered SVG)

```html
<svg width="100%" height="32" viewBox="0 0 220 32">
  <polyline
    fill="none"
    stroke="var(--color-emerald)"
    stroke-width="1.5"
    points="0,26 10,18 20,22 30,14 40,20 50,16 …"/>
</svg>
```

- ViewBox `220 × 32` for the heartbeat (60s window × 22 buckets)
- ViewBox `200 × 36` for the smaller sidebar pulse if used there
- Stroke color follows the metric semantics (emerald for healthy,
  amber for degraded, rose for failed)
- No JS — render server-side from the cache key on every page render

## What to Avoid

- **Drop shadows on flat surfaces.** Cards, sidebars, and tiles
  should rely on the 1 px border. Shadows are reserved for modals
  and the palette.
- **Pixel-perfect alignment via padding inflation.** Use the spacing
  scale; don't `padding: 7.5px`.
- **Mixing slate and gray.** Stick to slate. Tailwind v4's `slate-*`
  is the only acceptable neutral.
- **More than four status colors.** Emerald / amber / rose / blue
  cover OK / Warn / Fail / Info. Don't introduce purple "neutral" or
  teal "running".
- **Non-tabular numerics on amounts or counts.** Always tabular.

## Origin

Synthesized from sketch: 001 (cross-cutting primitives observed across
all four scenes).
Source files available in: `sources/001-phase-16-developer-mode/`.
Theme file: `sources/themes/default.css`.
