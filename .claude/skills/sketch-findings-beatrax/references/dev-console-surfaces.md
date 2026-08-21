# Dev Console Surfaces

## Design Decisions

### `/dev` overview — single dark "console pane"

The overview page is dominated by one big dark pane (`#0b1220` background,
`#f1f5f9` text) that holds three headline metrics + the rolled-up live
tail. The rest of the page (recent runs + open alerts cards) stays in the
calm slate room.

**Why it won over five-tile + audit (A) and compact strip + log tail
(B):** the console pane gives the overview a single focal subject
("what's the system doing right now?") instead of an even spread of
tiles. The localized dark surface signals "you are looking at a console"
without forcing the entire dev console into dark mode.

### Three headline metrics in the pane

Left-to-right, 28-32 px values, label uppercase 11 px:

1. **Worker heartbeat** — N `s ago` (emerald-300 if < 30 s, amber if
   30-60 s, rose if > 60 s) + a small inline sparkline of the last 60
   heartbeat ticks (60 s × 1 tick window each)
2. **Queue** — pending count + secondary `N failed · M batches` line in
   rose if failed > 0
3. **Last command** — command name (mono), exit code + duration + when +
   who below in muted text

### Console pane is theme-locked dark

The pane uses fixed dark colors that **don't follow `.dark` class**. In
light mode the rest of the page is white surfaces; the pane is a
deliberate dark inset. In dark mode the pane blends with the surrounding
slate-950, but it still maintains slightly different border/text tones
so it remains identifiable.

Tokens: `#0b1220` (pane bg), `#1e293b` (internal dividers), `#f1f5f9`
(value), `#94a3b8` (muted), `#6ee7b7` (positive), `#fbbf24` (warn),
`#fca5a5` (error). These do *not* live in the main theme — they're
inline on the pane.

### Live tail rolled into the pane

The bottom half of the pane is a monospace tail showing the last ~8
lines, each prefixed `[HH:MM:SS LEVEL]` with level coloring. Cursor
blinks at the end. Auto-scrolls. Hovering pauses auto-scroll. Click "↗
Expand" to open the full `/dev/logs` tailer (which uses the full log
component, not the inline rollup).

### Recent runs + open alerts below

Two side-by-side cards in the normal calm-slate styling:

- **Recent runs** — last 5 dev_mode_audit rows; mono command, tier
  chip, duration + exit code on the right. Each row is a link to
  `/dev/audit` filtered to that command.
- **Open alerts** — last N unack `system_alerts`; status pill + one-
  line title; "Open queue inspector →" or "Re-auth →" inline action
  link in `--color-blue`.

### `/dev/artisan` is a timeline, not a form

No persistent form on the page — commands fire from `⌘K`. The page
itself is a vertical timeline of run cards, grouped by day with small
day-section labels ("Today, 24 May" / "Yesterday, 23 May").

Filter chips at the top:

- All · N
- Running · N
- Failed · N
- Destructive · N

A primary `⌘K Run a command` button in the page header for users who
haven't internalized the palette habit yet.

### Run cards (live + done)

- **Running** — blue border + 3 px blue-bg glow, status pill with blue
  dot ("running"), streaming `<pre>` output area (max-height 180 px,
  scrolls), Cancel + Copy actions, run_id + pid in muted right margin
- **Done** — green dot, collapsed by default, "Show output" / "Re-run"
  / "Copy command" actions
- **Failed** — rose border, shows error output truncated to 80 px,
  "Show full output" + "Re-run" + "Copy command"

Header carries: status pill, command (mono), tier chip, meta (who +
when + duration). Clicking header collapses output.

### Triple-gate destructive modal

When the user attempts a destructive-tier command (or bulk destructive
queue action), this modal blocks until they type `Beatrax` exactly:

```
┌──────────────────────────────────────────────┐
│ ⚠ Destructive command — confirm              │   ← rose-tinted header bg
│ Dev Mode ON · Advanced ON · type the app name│
├──────────────────────────────────────────────┤
│ You are about to run:                        │
│ ┌──────────────────────────────────────────┐ │
│ │ php artisan db:restore --from=…          │ │   ← mono, slate-50 bg
│ └──────────────────────────────────────────┘ │
│ Type Beatrax to confirm                      │
│ ┌──────────────────────────────────────────┐ │
│ │ Beatrax|                                  │ │
│ └──────────────────────────────────────────┘ │
│ Case-sensitive · exact match required        │
├──────────────────────────────────────────────┤
│                       [Cancel]  [Run db:restore] │   ← rose; disabled until match
└──────────────────────────────────────────────┘
```

- Primary button (`Run …`) is disabled (50 % opacity) until the input
  exactly matches `Beatrax` (case-sensitive).
- Modal has no close-on-scrim-click — user must press Cancel or
  Escape.
- The resolved command line shown in the mono row is the actual
  resolved command (after arg-schema validation), not the schema
  template.

## CSS Patterns

### Console pane

```css
.console-pane {
  background: #0b1220;        /* fixed dark */
  color: #f1f5f9;
  border: 1px solid #1e293b;
  border-radius: var(--radius-lg);
  overflow: hidden;
}
.console-pane .head {
  padding: 18px 20px;
  display: grid;
  grid-template-columns: 1fr 1fr 1fr;
  gap: 28px;
  border-bottom: 1px solid #1e293b;
}
.console-pane .label {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: #94a3b8;
}
.console-pane .value-positive { color: #6ee7b7; }    /* emerald-300 */
.console-pane .value-warn { color: #fbbf24; }
.console-pane .value-error { color: #fca5a5; }
.console-pane .tail {
  padding: 14px 20px;
  font-family: var(--font-mono);
  font-size: 12px;
  line-height: 1.55;
  max-height: 240px;
  overflow: auto;
}
.console-pane .cursor {
  display: inline-block; width: 7px; height: 14px;
  background: #94a3b8; vertical-align: text-bottom;
  animation: blink 1s steps(1) infinite;
}
@keyframes blink { 50% { opacity: 0; } }
```

### Sparkline (heartbeat)

```html
<svg class="spark" width="100%" height="32" viewBox="0 0 220 32">
  <polyline fill="none" stroke="#10b981" stroke-width="1.5"
            points="0,26 10,18 20,22 30,14 …"/>
</svg>
```

Points are the last ~22 heartbeat timestamps mapped to viewBox X = i*10,
Y = 32 - (delta_ms_from_60s / 60_000 * 24). Stroke is `#10b981` (emerald).
Render server-side as static `<polyline>` to avoid client JS on the
heartbeat hot path.

### Run card

```css
.run-card {
  border: 1px solid var(--color-border);
  background: var(--color-surface);
  border-radius: var(--radius-lg);
  overflow: hidden;
  margin-bottom: 12px;
  transition: var(--tx-base);
}
.run-card.running {
  border-color: var(--color-blue);
  box-shadow: 0 0 0 3px var(--color-blue-bg);
}
.run-card.failed { border-color: var(--color-rose); }

.run-card-head {
  display: flex; align-items: center; gap: 12px;
  padding: 10px 14px;
  cursor: pointer;
}
.run-card-head .cmd {
  font-family: var(--font-mono);
  font-size: 13px; font-weight: 500;
}
.run-card-head .meta {
  font-size: var(--text-xs);
  color: var(--color-text-muted);
  margin-left: auto;
}
.run-card-out {
  background: #0b1220;        /* same dark inset as console pane */
  color: #e2e8f0;
  font-family: var(--font-mono); font-size: 12px;
  padding: 10px 14px;
  max-height: 180px; overflow: auto;
  border-top: 1px solid var(--color-border);
}
.run-card-out .out-info { color: #a3b8d4; }
.run-card-out .out-ok   { color: #6ee7b7; }
.run-card-out .out-err  { color: #fca5a5; }
.run-card-actions {
  display: flex; gap: 8px; padding: 8px 14px;
  background: var(--color-bg-subtle);
  border-top: 1px solid var(--color-border);
}
```

### Triple-gate modal

```css
.gate-card {
  background: var(--color-surface-raised);
  border-radius: var(--radius-xl);
  box-shadow: var(--shadow-palette);
  width: 460px;
  overflow: hidden;
}
.gate-head {
  padding: 16px 18px 14px;
  border-bottom: 1px solid var(--color-border);
  background: var(--color-rose-bg);
}
.gate-head .t {
  font-size: var(--text-md);
  font-weight: 600;
  color: var(--color-rose);
}
.gate-cmd {
  font-family: var(--font-mono);
  font-size: 12px;
  padding: 8px 10px;
  background: var(--color-bg-subtle);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  word-break: break-all;
}
```

## HTML Structures

### `/dev` overview pane

```html
<div class="console-pane">
  <div class="head">
    <div>
      <div class="label">Worker heartbeat</div>
      <div class="value tnum value-positive">4s <span class="muted">ago · ttl 60s</span></div>
      <svg class="spark" …></svg>
    </div>
    <div>
      <div class="label">Queue</div>
      <div class="value tnum">{{ $pending }} <span class="muted">pending</span></div>
      <div class="value-error">{{ $failed }} failed jobs · {{ $batches }} active batch</div>
    </div>
    <div>
      <div class="label">Last command</div>
      <div class="cmd">{{ $lastRun->command }}</div>
      <div class="value-positive">exit {{ $lastRun->exit_code }} · {{ $lastRun->duration }} · {{ $lastRun->started_at->format('H:i') }} by {{ $lastRun->caller }}</div>
    </div>
  </div>
  <div class="tail">
    @foreach($tailLines as $line)
      <div class="out-{{ $line->severityClass }}">{{ $line->display }}</div>
    @endforeach
    <span class="cursor"></span>
  </div>
</div>
```

### Day-grouped timeline

```html
<div class="filters">
  <button class="pill-btn">All · 24</button>
  <button class="pill-btn">Running · 1</button>
  <button class="pill-btn">Failed · 2</button>
  <button class="pill-btn">Destructive · 3</button>
</div>

@foreach($runsByDay as $day => $runs)
  <div class="side-section-label">{{ $day }}</div>
  @foreach($runs as $run)
    <x-dev::run-card :run="$run"/>
  @endforeach
@endforeach
```

## What to Avoid

- **A grid of five even tiles on `/dev` overview.** Considered (variant
  A) — looks busy on a 1280 px window and gives equal visual weight to
  things that aren't equally important. The console pane focuses
  attention on system pulse first.
- **A persistent form on `/dev/artisan`.** Considered (variants A & B)
  — locks one third of the page to a static input surface and
  encourages building command-specific UI inside the form. The palette
  flow keeps the runner page focused on history.
- **Light/dark variants of the console pane.** Don't try to theme it —
  the localized dark surface is the point. If a user finds the dark
  pane jarring in light mode, the broader Dev Console aesthetic isn't
  for them.
- **`alert` modals or `confirm()` for destructive actions.** Browser
  primitives don't theme, can't preview the resolved command, and
  can't enforce the typed-name pattern.
- **Closing the triple-gate modal on scrim click.** A click that
  bypasses the typed-name gate defeats the gate.

## Origin

Synthesized from sketch: 001 (Scene 2 — /dev overview; Scene 4 —
Artisan runner).
Source files available in: `sources/001-phase-16-developer-mode/`.
