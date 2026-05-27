# App Shell & Navigation

## Design Decisions

### Left sidebar replaces the top-nav (app-wide)

The existing `Modules/Core/Resources/views/livewire/top-nav.blade.php`
horizontal row is replaced by a fixed-width left sidebar (~248 px in the
main app, ~220 px inside `/dev/*`). The sidebar persists across every
authenticated route except auth/onboarding flows.

**Why it won:** Section labels turn 14 sibling nav items into 4 named
groups (This month / Money / Categorization / Tools), which is the move
that makes the navigation scannable. A flat list rotated 90° (the
cheaper variant) lost that scannability without buying enough vertical
density to compensate.

### Sectioned grouping (mandatory)

Four section labels, in this order:

1. **This month** — Dashboard
2. **Money** — Transactions, Imports, Inboxes, Recurring, Forecasts
3. **Categorization** — Uncategorized, Rules, Review chains
4. **Tools** — Receipts, Email, Drift alerts, Settings

Section labels are tiny uppercase (10.5 px / `0.06em` letter-spacing /
`--color-text-faint`). Empty sections (e.g. a v2 future without
Categorization) collapse — never render a label with no children.

### Sticky bottom Dev block (developer-only)

Inside the foot of the sidebar (above the account row), gated by
`is_developer`:

```
┌────────────────────────────┐
│ • Developer                │   ← uppercase 10px, dot pulses emerald
│ ›_ Open Dev Console   ⌘.   │   ← side-item with kbd hint
│ Queue 0 · Worker 4s ago    │   ← live pulse, 10.5px muted
└────────────────────────────┘
```

- **Dashed border** (`--color-border-strong`) marks it as a meta-block
  separate from regular nav.
- **Live pulse** uses `cache('dev_mode.queue_worker_heartbeat')` (D-39)
  for the worker line and the queue tile data source for the count.
- **`⌘.` shortcut** opens `/dev` directly. (`⌘K` is reserved for the
  palette across all surfaces.)
- **Non-developers never see this block** — the dashed container,
  heading, and content are all absent from the rendered DOM. Don't
  hide-with-CSS; the developer-only signal is server-side.

### Account row (always last)

```
┌────────────────────────────┐
│ ⦿  Wessel                ⋯ │
│    developer · local       │
└────────────────────────────┘
```

26 px gradient avatar (the user's initials over a calm
`linear-gradient(135deg, emerald, blue)` placeholder until profile
images ship). Two-line label, small caption below (developer status +
hostname). Trailing `⋯` opens the user menu. Clicking the row anywhere
opens the menu too.

### Workspace version chip

Top brand row carries a small `v2.0`-style chip on the right:

```
┌─ d  diederik              v2.0 ─┐
```

Renders from `config('app.version')`. Useful for support; non-load-
bearing visually.

### Dev Console sidebar (inside `/dev/*`)

When the user is on a `/dev/*` route, the sidebar narrows to ~220 px
and **replaces** its content (the app sidebar's section labels and
items are not rendered at all). The Dev Console sidebar shows:

- **Heading:** "Dev Console" + small amber `ON` chip
- **Nav items:** Overview, Artisan, Audit log, Logs, Queue (with badge
  for failed count), Doctor, SQL, Horizon (conditional per D-38),
  System
- **Foot:** `← Back to app` link styled as a muted text link, not a
  primary side-item

This is a hard swap, not a nesting — the Dev Console sidebar reuses
the same `.side` primitive (background, brand pattern, item styling)
but its content is owned by `Modules/DevMode/`.

## CSS Patterns

### Sidebar primitive

```css
.side {
  background: var(--color-bg-subtle);     /* slate-50 light, slate-950+ dark */
  border-right: 1px solid var(--color-border);
  display: flex; flex-direction: column;
  min-height: 100%;
  padding: 16px 12px;
}

.side-section-label {
  display: block;
  padding: 16px 10px 6px;
  font-size: 10.5px;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--color-text-faint);
  font-weight: 600;
}

.side-item {
  display: flex; align-items: center; gap: 9px;
  padding: 6px 10px;
  border-radius: var(--radius-md);
  color: var(--color-text-muted);
  font-size: var(--text-sm);
  transition: var(--tx-quick);
}
.side-item:hover { background: var(--color-surface-2); color: var(--color-text); }
.side-item.active {
  background: var(--color-surface-2);
  color: var(--color-text);
  font-weight: 500;
}
```

### Badge (live counts on nav items)

```css
.side-badge {
  margin-left: auto;
  display: inline-flex; align-items: center; justify-content: center;
  min-width: 18px; height: 18px;
  padding: 0 6px; border-radius: 9px;
  background: var(--color-text); color: var(--color-bg);
  font-size: 10.5px; font-weight: 600;
  font-variant-numeric: tabular-nums;
}
.side-badge.muted { background: var(--color-surface); color: var(--color-text); border: 1px solid var(--color-border); }
.side-badge.alert { background: var(--color-rose-bg); color: var(--color-rose); }
```

Three intensity levels:

- **default** — inverted (slate-900 fill on light) — for actionable
  counts the user is expected to clear (Inboxes, Review chains)
- **muted** — outlined — for counts that are FYI (Uncategorized,
  Recurring suggestions)
- **alert** — rose tinted — for drift alerts and dev queue failures

### Dev block (sticky foot)

```css
.side-dev-block {
  margin: 10px 0 6px;
  padding: 8px 8px 6px;
  border-radius: var(--radius-md);
  background: var(--color-surface-2);
  border: 1px dashed var(--color-border-strong);
}
.side-dev-block .heading {
  font-size: 10px; text-transform: uppercase; letter-spacing: 0.08em;
  color: var(--color-text-muted); font-weight: 600;
  padding: 0 2px 4px;
  display: flex; align-items: center; gap: 6px;
}
.dot-live {
  width: 6px; height: 6px; border-radius: 50%;
  background: var(--color-emerald);
  box-shadow: 0 0 0 3px var(--color-emerald-bg);
}
```

### Account row

```css
.side-account {
  display: flex; align-items: center; gap: 10px;
  padding: 6px 8px;
  border-radius: var(--radius-md);
  cursor: pointer;
}
.side-account:hover { background: var(--color-surface-2); }
.avatar {
  width: 26px; height: 26px; border-radius: 50%;
  background: linear-gradient(135deg, #10b981, #2563eb);
  display: grid; place-items: center;
  color: white; font-size: 11px; font-weight: 600;
}
```

## HTML Structures

### Full sidebar (main app)

```html
<aside class="side">
  <div class="side-brand">
    <div class="logo">d</div>
    diederik
    <span class="version-chip">v2.0</span>
  </div>
  <div class="side-search">
    Search or jump to… <span class="kbd">⌘K</span>
  </div>

  <div class="side-section-label">This month</div>
  <a class="side-item active"><span class="ic">◆</span> Dashboard</a>

  <div class="side-section-label">Money</div>
  <a class="side-item"><span class="ic">≡</span> Transactions</a>
  <!-- … -->

  <div class="side-foot">
    @if($isDeveloper)
    <div class="side-dev-block">
      <div class="heading"><span class="dot-live"></span> Developer</div>
      <a class="side-item" href="/dev">
        <span class="ic">›_</span> Open Dev Console
        <span class="kbd">⌘.</span>
      </a>
      <div class="dev-pulse">Queue {{ $queueCount }} · Worker {{ $heartbeatAgo }}</div>
    </div>
    @endif

    <div class="side-account">
      <div class="avatar">{{ $userInitials }}</div>
      <div>
        <span class="name">{{ $userName }}</span>
        <span class="caption">{{ $isDeveloper ? 'developer · local' : 'local' }}</span>
      </div>
      <span class="ic right">⋯</span>
    </div>
  </div>
</aside>
```

### Dev Console sidebar

```html
<aside class="dev-side">
  <div class="heading">
    <div class="title">Dev Console <span class="badge">on</span></div>
  </div>
  <nav>
    <a class="side-item active"><span class="ic">◆</span> Overview</a>
    <a class="side-item"><span class="ic">›_</span> Artisan</a>
    <a class="side-item"><span class="ic">≡</span> Audit log</a>
    <a class="side-item"><span class="ic">~</span> Logs</a>
    <a class="side-item"><span class="ic">⇉</span> Queue
      <span class="side-badge alert">{{ $failedCount }}</span>
    </a>
    <a class="side-item"><span class="ic">✓</span> Doctor</a>
    <a class="side-item"><span class="ic">⌗</span> SQL</a>
    @if($horizonAvailable)
      <a class="side-item"><span class="ic">↻</span> Horizon</a>
    @endif
    <a class="side-item"><span class="ic">ⓘ</span> System</a>
  </nav>
  <div class="side-foot">
    <a class="back" href="/">← Back to app</a>
  </div>
</aside>
```

## What to Avoid

- **A flat sidebar (variant A).** Loses the four-group scannability of
  the sectioned approach. Considered and rejected — costs ~32 px of
  vertical space and gains a meaningful improvement to "where is X?".
- **A sectioned sidebar with no Dev block (variant B).** Forces
  developers to ⌘K every time they want `/dev`. Considered — the Dev
  block earns its keep by keeping queue/worker pulse visible at the
  edge of every screen, not just `/dev`.
- **Visible Dev block for non-developers.** Don't hide-with-CSS; the
  block (heading, content) must be server-side absent from the DOM.
- **Section labels with no items.** Empty sections collapse.
- **Nested Dev Console sidebar that adds to the main sidebar.** The
  swap is hard — inside `/dev/*` the main sidebar's items are not
  rendered. Reuses the `.side` primitive but its nav source is
  different.
- **`⌘K` rebinding by the Dev block.** `⌘K` is the palette across
  every surface; the Dev block uses `⌘.` for "open Dev Console" so
  there's no overlap.

## Origin

Synthesized from sketch: 001 (Scene 1 — App sidebar; Scene 2 — Dev
Console sidebar).
Source files available in: `sources/001-phase-16-developer-mode/`.
