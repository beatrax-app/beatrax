# First-Import Review Step

The final wizard step (Step 5 of 6) — the consolidated "review and
commit" surface that takes every stashed ImportRun (bank via
CAMT/MT940/CSV, ICS via PDFs, PayPal CSV, future email-attached
sources) and turns the lot into a single primary action:
**"Commit everything (N transactions) →"**.

This is the **only** wizard step that relaxes the locked 620px card
width to the wide-card exception (~1120px), to fit the per-source
preview tables.

## Design Decisions

### Wide-card exception (sketch 002 follow-through)

- The wizard `wiz-card` keeps its standard 620px on every other step.
- For FirstImportStep only, the card becomes `wiz-card.wide` with
  `max-width: 1120px`. Padding stays the same (`44/48/32` on tablet
  and up; reduces gracefully under 900px).
- Top progress dots, footer privacy pill, and "Resume later" do not
  change.

### Sub-card per source (sketch 006, variant B) — the body composition

Each per-source preview is its own **framed sub-card** inside the
wizard card:

- `border: 1px solid var(--color-border)`
- `border-radius: var(--radius-lg)` (8px)
- `background: var(--color-bg-subtle)` — the same color the *page*
  uses behind the wizard card. The sub-card reads as **carved out of
  the page wash**, not "stacked card on card".
- `padding: 22px 24px`
- Vertical gap between sub-cards: `margin-top: 18px`
- The preview eyebrow lives inside the sub-card, not above it:
  `🏦 FROM YOUR BANK STATEMENT · 84 ROWS · ✓ READY`

**Empty-section fill swap.** A section with `status === 'empty'`
swaps its sub-card fill from `--color-bg-subtle` to
`--color-surface-2`. This is a quiet, ~one-token difference — enough
that the user reads "this one is different" without it competing for
attention with the ready sources. The body copy stays the same
italic muted line: `"This statement is empty — every row was
already imported."`

**Error and filtered status fills.** Apply the same primitive: error
sections get a rose-tinted border, filtered sections get the
`surface-2` fill plus a muted note line. These were not
demonstrated in sketch 006 but follow the same primitive directly.

### Starting-balance block (sketch 006, variant B) — placement and grid

After the last preview sub-card, a **framed balance block** holds all
starting-balance cards:

- Same sub-card frame as the preview sub-cards (border + radius +
  `--color-bg-subtle` fill).
- `margin-top: 28px` separates it from the last preview sub-card.
- Block eyebrow: `🧮 STARTING BALANCES · 3 ACCOUNTS DETECTED`
  (same `.preview-section-eyebrow` shape as the preview sub-cards).
- One-line lede beneath: "We detected the starting balance for each
  account. Confirm or edit before we commit."
- Balance cards inside the block in a **3-up CSS grid**:
  `grid-template-columns: repeat(3, 1fr); gap: 16px`.
- Each balance card uses the existing `.balance-card` atom (border +
  radius + surface bg) — no nesting visual change.

**Why a 3-up grid replaces the live app's full-width stack.** The
densest balance card state is the conflict state (two radio rows +
helper line + actions). The current full-width stack renders this in
a column that's far wider than the content needs, which makes the
simpler `detected` and `manual-entry` states look stranded. The 3-up
grid:

- Renders all three states (detected / conflict / manual-entry) at
  the same width, so they read as siblings.
- Compresses the page so the user doesn't scroll past balances to
  find the commit footer.
- Wraps gracefully at narrow widths (see "Responsive collapse"
  below).

### Eyebrow weight stays unchanged

The eyebrow `🏦 FROM YOUR BANK STATEMENT · 84 ROWS · ✓ READY`
keeps its existing shape: uppercase + `letter-spacing: 0.06em` +
`--text-xs`, with the count in `--color-text-faint` and the status
badge color-coded per status (`ready` = emerald, `empty` = amber,
`filtered` = faint, `error` = rose).

The framed sub-card does **not** make the eyebrow redundant — the
eyebrow *labels* the source, the frame *groups* its rows. Both jobs
are needed.

### Commit footer

The commit footer sits below the balance block (same level as the
sub-cards):

- `margin-top: 28px` separates it from the balance block.
- `border-top: 1px solid var(--color-border)` provides a thin
  divider.
- Layout: `flex justify-content: space-between` — counter line on
  the left, big primary CTA on the right.
- Counter: `<strong>128</strong> transactions to commit · <span>17</span>
  already imported` (tabular numerics).
- CTA: `commit-btn-primary` with copy `Commit everything (128
  transactions) →`. Disabled when no section is `ready`.

### No new theme tokens (phase 16.1.2 D-17)

Every decision above uses tokens that already ship in
`themes/default.css`:

- Surfaces: `--color-surface`, `--color-bg-subtle`, `--color-surface-2`
- Borders: `--color-border`, `--color-border-strong`
- State: `--color-emerald` / `-bg`, `--color-amber` / `-bg`,
  `--color-rose` / `-bg`, `--color-blue` / `-bg`
- Radius: `--radius-lg`, `--radius-sm`
- Text: `--color-text`, `--color-text-muted`, `--color-text-faint`

If a future variant would need a new token (e.g. a separate
"sub-card border" color), surface that as a discussion before
shipping — the design contract says no new tokens.

## CSS Patterns

### Wide-card exception

```css
.wiz-card.wide { max-width: 1120px; }
```

### Sub-card per source

```css
.source-subcard {
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  background: var(--color-bg-subtle);
  padding: 22px 24px;
  transition: var(--tx-quick);
}
.source-subcard + .source-subcard { margin-top: 18px; }

/* Empty-state fill swap */
.source-subcard.empty { background: var(--color-surface-2); }

/* Inside the sub-card, table-row hover swaps to surface so the
   row stands out against the sub-card fill */
.source-subcard .preview-section-table tbody tr:hover {
  background: var(--color-surface);
}
```

### Starting-balance block

```css
.balance-section-subcard {
  margin-top: 28px;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  background: var(--color-bg-subtle);
  padding: 22px 24px;
}

.starting-balance-stack {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 16px;
  margin-top: 16px;
}

.starting-balance-lede {
  margin: 0;
  color: var(--color-text-muted);
  font-size: var(--text-sm);
  line-height: 1.5;
}
```

### Responsive collapse (recommend for the CSS plan)

The 3-up balance grid and the wide card both need narrow-width
fallbacks. Wave-1 CSS plan picks the exact breakpoints; suggested:

```css
/* Drop from 3-up to 2-up where individual cards would otherwise
   become too cramped to render the conflict state cleanly */
@media (max-width: 1024px) {
  .starting-balance-stack {
    grid-template-columns: repeat(2, 1fr);
  }
}

/* Stack to a single column on phone-class widths */
@media (max-width: 720px) {
  .starting-balance-stack {
    grid-template-columns: 1fr;
  }
  .wiz-card.wide {
    /* Already responsive via max-width, but reduce padding to
       give back screen space at narrow widths */
    padding: 28px 24px 24px;
  }
}
```

### Commit footer

```css
.commit-footer {
  margin-top: 28px;
  padding-top: 20px;
  border-top: 1px solid var(--color-border);
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 16px;
  flex-wrap: wrap;
}
.commit-counter { margin: 0; font-size: var(--text-sm); color: var(--color-text-muted); }
.commit-counter strong { color: var(--color-text); font-weight: 600; }
.commit-btn-primary {
  padding: 11px 22px; border-radius: var(--radius-md);
  background: var(--color-primary); color: var(--color-primary-text);
  border: 1px solid var(--color-primary);
  font-size: var(--text-md); font-weight: 500; cursor: pointer;
  transition: var(--tx-quick);
}
.commit-btn-primary:hover { opacity: 0.94; }
.commit-btn-primary[disabled] { opacity: 0.4; cursor: not-allowed; }
```

## HTML Structures

### Page skeleton (inside the wizard card)

```html
<x-onboarding::wiz-card :wide="true">
  <p class="wiz-eyebrow">📥 Step 5 — Review &amp; commit</p>
  <h1 class="wiz-h1">Review everything we found</h1>
  <p class="wiz-lede">
    <strong class="tnum">{{ $preview->dedupedTotalCount }}</strong> transactions across
    <strong class="tnum">{{ $sourceCount }}</strong>
    {{ $sourceCount === 1 ? 'source' : 'sources' }}.
    Confirm your starting balances, then commit.
  </p>

  {{-- One sub-card per source --}}
  @foreach ($preview->sections as $section)
    <section class="source-subcard {{ $section->status === 'empty' ? 'empty' : '' }}">
      <x-onboarding::consolidated-preview-section :section="$section" />
    </section>
  @endforeach

  {{-- Starting-balance block (only if any account was detected
       OR manual-entry is required) --}}
  @if ($detectedCount > 0)
    <section class="balance-section-subcard">
      <p class="preview-section-eyebrow">
        🧮 <span class="preview-section-label">STARTING BALANCES</span>
        <span class="preview-section-count">· {{ $detectedCount }} {{ $detectedCount === 1 ? 'ACCOUNT DETECTED' : 'ACCOUNTS DETECTED' }}</span>
      </p>
      <p class="starting-balance-lede">
        We detected the starting balance for each account. Confirm or edit before we commit.
      </p>
      <div class="starting-balance-stack">
        @foreach ($balancesByAccount as $accountId => $candidates)
          <livewire:onboarding.starting-balance-card :key="'sb-'.$accountId" {{-- ... --}} />
        @endforeach
      </div>
    </section>
  @endif

  <div class="commit-footer">
    <p class="commit-counter" role="status" aria-live="polite">
      <strong class="tnum">{{ $preview->dedupedTotalCount }}</strong>
      {{ $preview->dedupedTotalCount === 1 ? 'transaction' : 'transactions' }} to commit ·
      <span class="tnum">{{ $preview->alreadyImportedCount }}</span> already imported
    </p>
    <button type="button" class="commit-btn-primary" wire:click="commit"
      @if ($commitDisabled) aria-disabled="true" disabled @endif>
      {{ $commitButtonLabel }}
    </button>
  </div>
</x-onboarding::wiz-card>
```

### Per-source sub-card

The `<x-onboarding::consolidated-preview-section>` component renders
the preview-section eyebrow + table (or empty/error/filtered body)
inside the sub-card. The wrapping `<section class="source-subcard">`
is the only structural change — the existing component's internals
do not need to know whether they're being framed or not.

Apply the `empty` class on the wrapping `<section>` when
`$section->status === 'empty'` to trigger the fill swap.

## What to Avoid

- **Single-frame stack (sketch 006 variant A)** — the current live
  shape, with preview sections flush against the wizard card and a
  3-up balance grid at the end. It reads as one big undifferentiated
  block. The user can't easily tell where one source ends and the
  next begins beyond the eyebrow line.
- **Inline balances per source (sketch 006 variant C)** — pairs each
  account's balance card with its preview rows in a two-column row.
  Pretty in mockup, but assumes 1:1 source↔account. As soon as a
  user imports two ASN accounts in one step (or one PayPal CSV
  containing two PayPal accounts), the pairing story falls apart.
- **Sticky balance rail (sketch 006 variant D)** — `position: sticky`
  right rail with balance cards while previews scroll on the left.
  Practical, but the column-with-sticky-rail shape reads like an
  admin panel rather than a calm-Notion wizard. Breaks the visual
  family with sketches 002/003.
- **Framing the wizard card eyebrow + H1 + lede.** Don't put the
  eyebrow/H1/lede inside their own sub-card. That hierarchy lives
  at the wizard-card level — the sub-cards are for sources and the
  balance block only.
- **Adding a new theme token for the sub-card border or fill.** The
  contract is to reuse the existing slate-50/100/200/700/900/950 +
  emerald/amber/rose/blue palette. If a future state genuinely needs
  a new token (e.g. a "warn" sub-card with an amber-tinted border),
  surface that in a sketch first; don't introduce tokens mid-build.
- **Reducing the wide-card max-width below 1120px.** The width was
  arrived at empirically — narrower, and the 4-column preview table
  (Date · Type · Counterparty · Amount) starts wrapping the
  counterparty column ungracefully. Wider, and the wizard chrome
  feels out of family with the rest of the steps.
- **Removing the wizard chrome around FirstImportStep.** Even though
  this step is much denser than the others, it stays inside the same
  wizard `wiz-page` + top progress dots + footer privacy pill. The
  user is still in the wizard; commit hasn't happened yet.

## Origin

Synthesized from sketch 006 (`006-phase-16-1-2-first-import-step-layout`).
Source file: `sources/006-phase-16-1-2-first-import-step-layout/index.html`.

Resolves the FirstImportStep visual ambiguity flagged in Phase 16.1.1
UAT (the live-app starting-balance stack and the page-level eyebrow
weight were both too noisy). Unblocks Phase 16.1.2 plans A-3
(starting-balance section tidy) and A-4 (preview body-block layout)
— the planner may merge those two plans into one CSS plan since they
share a single composition contract.
