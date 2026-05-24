# Command Palette (⌘K)

## Design Decisions

### Two-pane layout

The palette modal is split into a left categories rail + a right results
list:

```
┌──────────────────────────────────────────────────────────┐
│ ⌕ Search views, commands, and actions…           [esc]   │
├──────────────────────────────────────────────────────────┤
│ All · 23      │ ◆ Dashboard       [view]              ↩  │
│ Views · 11    │ ≡ Transactions    [view]              ↩  │
│ Dev cmds · 9  │ ›_ beatrax:doctor [dev · safe]        ↩  │
│ Actions · 4   │ ⊕ Scan email now  [action]            ↩  │
│ ─────────     │ ⚙ Settings · Backups [view]           ↩  │
│ Recent        │ ›_ db:backup      [dev · safe]        ↩  │
│ ↻ doctor      │                                          │
│ ↻ Backups     │                                          │
├──────────────────────────────────────────────────────────┤
│ ↑↓ rows · ⇥ categories · ↩ select       Showing all · 23 │
└──────────────────────────────────────────────────────────┘
```

**Why it won over Raycast-style single list (A) and grouped sections
(B):** the palette is now the *primary* command-entry point — every
artisan command, every navigation jump, every named app action enters
through it. The two-pane gives a persistent Recent shortcuts list (you
re-run the same five commands 90 % of the time) and category filters
without making the results scroll noisier. Linear's palette is the
reference.

### Width

760 px wide (vs. the 640 px single-list variant). Drops back to 640 px
on viewports below 1100 px (handled by Flux's modal breakpoint, not
custom CSS).

### Sources

Three Public registries (per Phase 16 D-41), surfaced as palette rows:

- **NavigationRegistry** — every authenticated view. Source tag:
  `view` in muted slate.
- **DevCommandRegistry** — SAFE-tier only. Source tag: `dev · safe`
  in amber. **DESTRUCTIVE-tier commands never appear in the palette**
  (the muscle-memory-disaster guardrail).
- **AppActionRegistry** — named app actions like "Scan email now",
  "Open backups folder", "Run import". Source tag: `action` in blue.

Non-developers only see Views + Actions sources; the Dev cmds category
row is absent from the rail and the source can't match.

### Recent shortcuts

The category rail has a divider with a "Recent" sub-list (last 5
selected entries, mixed across sources). Up to 5 rows, deduplicated by
url/handler. Persisted per-user in `cache('dev_mode.palette_recent.'.$userId)`,
TTL 30 days.

### Empty state

When the search box is empty and Recent is empty too:

```
"Type to search views, commands, and actions.
Press Esc to close."
```

Centered in the results pane, muted. (Verbatim from Phase 16 specifics.)

### Source chips

Right-aligned mini chip on every row:

- `view` — `--color-surface-2` bg / `--color-text-muted` text
- `dev · safe` — `--color-amber-bg` / `--color-amber`
- `action` — `--color-blue-bg` / `--color-blue`

The two-word `dev · safe` chip is deliberate — it pre-empts "is this a
dangerous thing?" before the user presses ↩.

### Keyboard model

- **`⌘K` / `Ctrl+K`** — open from anywhere. Bound on `<body>` via Alpine
  `x-data` handler. `event.metaKey || event.ctrlKey` covers both
  platforms.
- **`Esc`** — close.
- **`↑` / `↓`** — move selection within the right pane.
- **`Tab`** — toggle focus between right pane (rows) and left pane
  (categories).
- **`↩`** — execute the selected row's handler (navigate or run).
- **`⌘⌫` (on a Recent row)** — remove from recents.

### Scrim + blur

`rgba(8, 12, 20, 0.45)` overlay with `backdrop-filter: blur(4px)
saturate(120%)`. Calm — not the opaque-dark scrim that some palettes
use. Lets the user keep peripheral awareness of the page underneath.

### Animation

`160ms` cubic-bezier ease-smooth, from `opacity: 0;
transform: translateY(-6px) scale(0.98)` to default. Subtle — never
draws attention to the modal itself.

## CSS Patterns

```css
.scrim {
  position: fixed; inset: 0;
  background: rgba(8, 12, 20, 0.45);
  backdrop-filter: blur(4px) saturate(120%);
  z-index: 20;
  display: grid; place-items: start center;
  padding-top: 80px;
}

.palette {
  width: 760px;       /* two-pane variant */
  background: var(--color-surface-raised);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-xl);
  box-shadow: var(--shadow-palette);
  overflow: hidden;
  animation: pop 160ms var(--ease-smooth);
}
@keyframes pop {
  from { opacity: 0; transform: translateY(-6px) scale(0.98); }
  to   { opacity: 1; transform: translateY(0) scale(1); }
}

.palette-input {
  display: flex; align-items: center; gap: 10px;
  padding: 14px 16px;
  border-bottom: 1px solid var(--color-border);
}
.palette-input input {
  flex: 1; border: 0; outline: 0; background: transparent;
  font-size: var(--text-md);
  color: var(--color-text);
  font-family: var(--font-sans);
}

.palette-body {
  display: grid;
  grid-template-columns: 180px 1fr;
  min-height: 320px;
}

.palette-rail {
  background: var(--color-bg-subtle);
  border-right: 1px solid var(--color-border);
  padding: 8px;
}

.palette-row {
  display: grid;
  grid-template-columns: 22px 1fr auto auto;
  gap: 12px;
  align-items: center;
  padding: 8px 16px;
  cursor: pointer;
  border-left: 2px solid transparent;
}
.palette-row:hover,
.palette-row.selected {
  background: var(--color-surface-2);
  border-left-color: var(--color-text);
}

.palette-source {
  font-size: 10.5px;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  font-weight: 600;
  padding: 2px 7px;
  border-radius: var(--radius-sm);
  background: var(--color-surface-2);
  color: var(--color-text-muted);
}
.palette-source.dev    { background: var(--color-amber-bg); color: var(--color-amber); }
.palette-source.action { background: var(--color-blue-bg);  color: var(--color-blue); }

.palette-foot {
  display: flex; gap: 16px; align-items: center;
  padding: 8px 16px;
  border-top: 1px solid var(--color-border);
  background: var(--color-bg-subtle);
  font-size: 11px;
  color: var(--color-text-muted);
}
```

## HTML Structure

```html
<div class="scrim" x-show="open" x-transition>
  <div class="palette" @click.outside="open = false">
    <div class="palette-input">
      <span>⌕</span>
      <input x-model="q" x-ref="qInput" placeholder="Search views, commands, and actions…">
      <span class="kbd">esc</span>
    </div>

    <div class="palette-body">
      <nav class="palette-rail">
        <a class="side-item" :class="{ active: cat==='all' }" @click="cat='all'">
          All <span class="side-badge muted">{{ counts.all }}</span>
        </a>
        <a class="side-item">Views <span class="side-badge muted">{{ counts.view }}</span></a>
        @if($isDeveloper)
          <a class="side-item">Dev commands <span class="side-badge muted">{{ counts.dev }}</span></a>
        @endif
        <a class="side-item">Actions <span class="side-badge muted">{{ counts.action }}</span></a>

        <div class="sep"></div>
        <div class="side-section-label">Recent</div>
        @foreach($recents as $r)
          <a class="side-item">↻ {{ $r->label }}</a>
        @endforeach
      </nav>

      <div class="palette-results">
        @foreach($results as $row)
          <a class="palette-row" :class="{ selected: idx === $loop->index }">
            <span class="ic">{{ $row->icon }}</span>
            <div>
              <span class="label">{{ $row->label }}</span>
              <span class="hint">{{ $row->hint }}</span>
            </div>
            <span class="palette-source {{ $row->sourceClass }}">{{ $row->sourceLabel }}</span>
            <span class="kbd">↩</span>
          </a>
        @endforeach
      </div>
    </div>

    <div class="palette-foot">
      <span><span class="kbd">↑</span> <span class="kbd">↓</span> rows · <span class="kbd">⇥</span> categories</span>
      <span><span class="kbd">↩</span> select</span>
      <span class="right">{{ $resultsCount }} results</span>
    </div>
  </div>
</div>
```

## Fuse.js Configuration

Per Phase 16 D-40, ranking happens client-side via Fuse.js. The server
emits a JSON registry on mount (the same shape as the row above, plus
internal fields):

```json
{
  "items": [
    {
      "id": "view:dashboard",
      "label": "Dashboard",
      "hint": "This period at a glance",
      "source": "view",
      "icon": "◆",
      "url": "/",
      "keywords": ["home", "month", "totals"]
    },
    {
      "id": "dev:beatrax-doctor",
      "label": "beatrax:doctor",
      "hint": "Run the health probe",
      "source": "dev",
      "tier": "safe",
      "icon": "›_",
      "handler": "dev.artisan.run",
      "args": { "command": "beatrax:doctor" },
      "keywords": ["health", "check", "probe", "diagnose"]
    }
  ]
}
```

Fuse options:

```js
{
  keys: [
    { name: 'label',    weight: 0.65 },
    { name: 'hint',     weight: 0.20 },
    { name: 'keywords', weight: 0.15 }
  ],
  threshold: 0.35,
  ignoreLocation: true,
  includeScore: false,
  minMatchCharLength: 1
}
```

## What to Avoid

- **A single flat list with source chips (variant A).** Looked clean
  but provided no Recent affordance and no escape hatch when the user
  wanted to browse rather than search. The two-pane earns the persistent
  Recent rail.
- **Grouped sections (variant B).** Works for browsing but wastes
  vertical space when fuzzy-searching, and `⌘1/⌘2/⌘3` to jump sections
  duplicates what categories already provide.
- **Surfacing DESTRUCTIVE-tier commands.** Hard rule: never. The
  muscle-memory-disaster guardrail is the entire reason the palette
  filters dev commands to safe-tier only.
- **A loading spinner inside the palette while Fuse warms up.** Fuse
  builds its index in a few ms on a 100-entry registry. Either index
  is ready or it isn't — never show an animation.
- **Closing the palette on any selection.** Selection that triggers
  navigation closes the palette; selection that triggers an action
  (e.g. "Scan email now") closes the palette and shows a toast.
  Selection that opens a sub-flow (e.g. dev command with required args
  — see future enhancement) replaces the palette body with the arg
  form, doesn't open a separate modal.

## Future Enhancement (deferred from sketch)

Sketch 001 mocked the palette as a pure selector — every dev command
displayed runs with defaults. The real implementation per Phase 16
D-15 needs declarative arg-form schemas. Two compatible directions for
when arg forms enter the palette:

1. **Inline arg form** — after selecting a dev command with required
   args, the palette body swaps to a stacked form with `Esc` → back to
   results, `↩` → run. Most palette-native.
2. **Modal handoff** — selecting the command closes the palette and
   opens a Flux modal with the arg form. Easier to build, weaker UX.

This decision is deferred; flag it in the discuss/plan loop before
implementing the runner.

## Origin

Synthesized from sketch: 001 (Scene 3 — ⌘K palette; Scene 4 —
palette-dispatched runner).
Source files available in: `sources/001-phase-16-developer-mode/`.
