# Counterparty Profile Pages

The `/counterparties/{slug}` profile page surfaces everything Beatrax knows
about one entity that touches the user's money. **"Counterparty"** spans
five typed kinds — `merchant`, `personal`, `bank`, `government`,
`self_account` — plus an `unknown` fallback. One shape (a tabbed surface)
flexes across all five via per-type variations.

## Design Decisions

### Profile shape: tabbed surface (sketch 007 winner C)

- **Compact hero** (avatar + name + type chip + 1-line meta + 1-2 key
  stats + edit affordance) on a single row. Stat-stack is right-aligned;
  hero has `border-bottom: 1px solid var(--color-border)` only when the
  tab bar isn't immediately below.
- **Tab bar** (`Overview · Transactions · Chains · Aliases` for the
  merchant baseline) sits directly below the hero. Tab style: padding
  `8px 14px`, font-size `var(--text-sm)`, font-weight 500. Active tab
  gets `color: var(--color-text) + border-bottom-color: var(--color-primary)`
  (the bar bottom-border-1px doubles as both separator and active-tab rail).
- **Per-tab count chip** — small monospace pill `font-family: var(--font-mono); font-size: 10px; padding: 1px 6px; border-radius: 8px`. Inactive tabs show count on
  `var(--color-surface-2)`; active tab inverts to `var(--color-text) /
  var(--color-bg)`.
- **Overview tab body** — 2-column CSS grid (`grid-template-columns: 1fr 1fr; gap: 24px`).
  Left column: **Categories** breakdown + **Recurring patterns**. Right
  column: **Recent activity** (5 rows max in a frame, with "See all N →"
  link that programmatically switches to Transactions tab) + **Funding
  chain** summary (with "Open Chains →" link that switches to Chains tab).

### Hero stat composition

- **Merchant**: `12-month total` (with ↑/↓ delta vs prior 12mo) + `Average / month`
- **Personal**: `Net received` (color green if positive, default text if negative)
  + sub-line breaking out `€X in − €Y out`
- **Bank**: `12-month total` (delta) + `Net of interest`
- **Government**: `2026 YTD` (current tax year emphasized) + `2025 final`
  (with vs-prior-year sub)
- **Self-account**: minimal hero, no stats — replaced by stub-redirect body

### Tab-bar variation per type (sketch 008)

- **Merchant**: `Overview · Transactions · Chains · Aliases` (canonical)
- **Personal**: `Overview · Transfers · Aliases` — Chains dropped (P2P
  transfers don't have funding chains). Tab-note appended on the right:
  `— no funding chains for personal contacts`.
- **Bank**: `Overview · Entries · Aliases` — Chains dropped. Tab-note:
  `— bank-fee counterparty doesn't generate funding chains`.
- **Government**: `Overview · Payments · Tax years · Aliases` — Chains
  dropped; **`Tax years` tab added**. Tab-note: `— no funding chains for government counterparties`.
- **Self-account**: no tab bar at all — body is a stub redirect.

### Type-aware visual language

Five type-chip colors (mirrors counterparty-index colors):

```css
.t-merchant { background: var(--color-blue-bg); color: var(--color-blue); border-color: color-mix(in srgb, var(--color-blue) 24%, transparent); }
.t-personal { background: #fce7f3; color: #be185d; border-color: color-mix(in srgb, #be185d 24%, transparent); }
.t-bank     { background: var(--color-amber-bg); color: var(--color-amber); border-color: color-mix(in srgb, var(--color-amber) 24%, transparent); }
.t-gov      { background: #e2e8f0; color: #334155; border-color: #cbd5e1; }
.t-self     { background: var(--color-surface-2); color: var(--color-text-muted); border-color: var(--color-border); }
.t-unknown  { background: transparent; color: var(--color-text-muted); border: 1px dashed var(--color-border-strong); }
```

Hero avatar uses a per-type gradient. Sizes: `lg` = 56×56 px, `radius-xl`,
`font-weight: 700; font-size: 22px`. White text on the gradient. Personal
avatar uses pink gradient (`#ec4899 → #be185d`), bank uses amber
(`#f59e0b → #b45309`), gov uses slate (`#64748b → #334155`), self uses
muted (`#94a3b8 → #64748b`). Merchant avatars typically take the
brand color (Amazon: orange gradient, Albert Heijn: orange, Netflix: red,
KPN: blue, Bol.com: emerald).

### Personal-type privacy defaults (sketch 008 variant A)

This is **load-bearing** — personal counterparties are family / partners,
not commercial entities. Defaults err on hiding.

- **Privacy banner** at the top of the profile (above the hero):

  ```html
  <div class="privacy-banner">
    <span class="icon">🔒</span>
    <span>This is a personal contact. IBAN and personal details are
    hidden by default and never shared in exports.</span>
  </div>
  ```

  Styles: `padding: 8px 12px; background: #fce7f3; border: 1px solid #fbcfe8; border-radius: var(--radius-md); font-size: var(--text-xs); color: #831843`.

- **IBAN hidden by default**, behind a "Show IBAN" button:

  ```html
  <div class="iban-row">
    <div>
      <div class="iban-label">IBAN</div>
      <div class="iban-hidden">····  ····  ····  ····</div>
    </div>
    <button class="btn">Show IBAN</button>
  </div>
  ```

  The IBAN-hidden span uses `font-family: var(--font-mono); color: var(--color-text-faint); letter-spacing: 0.4em` — visibly redacted.
  On reveal: `Show IBAN → Hide IBAN` toggle; auto-hides on page navigation
  away (re-hide on next mount).

- **Categories replaced with purpose tags**. P2P transfers don't fit
  spending categories. Tags are user-authored chips like `birthday`,
  `rent split`, `groceries shared` — each chip shows a count badge.
  `+ Add tag` chip in dashed-border style.

- **Aliases section minimized** — personal counterparties typically have
  one display name (`Mama`, `Sara · partner`) and rarely accumulate
  variants. The Aliases tab still exists for completeness.

- **Recurring section replaced with a quiet "no recurring detected" frame**
  with copy explaining "Personal transfers rarely follow a strict cadence
  — even regular rent splits may shift dates."

### Bank-type subtitle disambiguates (sketch 008 variant B)

A `bank` counterparty represents the bank **as a fee-charging /
interest-paying party**, not as the user's account holder. To prevent
confusion:

- Hero title gets a subtitle: `ASN Bank · fees & interest`
- Footer of the Overview tab includes a quiet link-out:
  `For the account balance & statement view, open Accounts → ASN Bank`
- Fee-type breakdown uses **horizontal mini-bar rows** (not a stacked
  bar):

  ```html
  <div class="fee-bar-row">
    <span class="fee-label">Account fee</span>
    <div class="fee-bar-track"><div class="fee-bar-fill" style="width: 100%"></div></div>
    <span class="fee-total">− € 39.00</span>
  </div>
  ```

  Grid: `grid-template-columns: 130px 1fr 70px; gap: 12px`. Bar fill =
  `var(--color-amber)` for fees; interest income flips to
  `var(--color-emerald)` with the label `↓ Interest income` (downward
  arrow = "money coming in").

### Government-type tax-year breakdown (sketch 008 variant C)

The **headline** for a government counterparty is the multi-year tax
breakdown — a full-width row of three year cards positioned **above**
the Overview grid (between the tab bar and the 2-col body).

- 3 cards in `grid-template-columns: 1fr 1fr 1fr; gap: 10px`
- Current year card gets `border-color: var(--color-text); border-width: 1.5px; box-shadow: var(--shadow-xs)` — emphasized
- Each card: year label (small, uppercase) + total (large, tabular) +
  payment count + status
- Current year card may include a **pending-assessment chip** if there's
  an upcoming Aanslag: `⚠ Aanslag IB 2025 due 30 Jun 2026 · € 480`
  styled in amber (`background: var(--color-amber-bg); color: var(--color-amber)`)
- Categories breakdown uses **tax-type swatches** (Inkomstenbelasting =
  rose, BTW = amber, Motorrijtuigenbelasting = slate, Toeslagen terug =
  faint-slate) rather than spending categories

### Self-account stub (sketch 008 variant D)

`self_account` counterparties are the user's OWN accounts appearing in
cross-account legs. They shouldn't be a real profile page — but they
DO get clicked on accidentally. The page is a **stub redirect**:

```html
<div class="self-stub">
  <div class="stub-icon">↻</div>
  <h2>This isn't really a counterparty</h2>
  <p>
    PayPal appears here because it shows up in your transactions as the
    funding leg between accounts. But it's <strong>your own
    account</strong>, not someone you transact with.<br>
    Open the account view for balance, statements, and full transaction
    history.
  </p>
  <div class="stub-actions">
    <button class="btn btn-primary">Open PayPal account view →</button>
    <button class="btn">Hide from this list</button>
  </div>
</div>
```

Styles: `max-width: 640px; margin: 60px auto; padding: 40px; border: 1px
dashed var(--color-border-strong); border-radius: var(--radius-xl);
background: var(--color-bg-subtle); text-align: center`.

Below the stub, a small section shows **recent cross-account legs** for
context (max ~3 rows) so the user can confirm they're looking at the
right thing.

## CSS Patterns

### Frame block (reused across Overview tab + bank fee block)

```css
.frame { border: 1px solid var(--color-border); border-radius: var(--radius-lg); background: var(--color-bg-subtle); padding: 16px; }
.frame-tight { padding: 12px 14px; }
.frame.surface { background: var(--color-surface); }
```

### Recurring patterns card

```css
.recur-card {
  padding: 12px 14px; border: 1px solid var(--color-border);
  border-radius: var(--radius-md); background: var(--color-surface);
}
.recur-cadence {
  display: inline-flex; align-items: center; gap: 4px; font-size: 10px;
  padding: 2px 7px; border-radius: var(--radius-full);
  background: var(--color-emerald-bg); color: var(--color-emerald);
  margin-left: 6px; font-weight: 500;
}
.recur-cadence.amber { background: var(--color-amber-bg); color: var(--color-amber); }
```

### Funding chain flow (compact)

```css
.chain-flow { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; font-size: var(--text-sm); }
.chain-node {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 6px 10px; border-radius: var(--radius-md);
  background: var(--color-surface); border: 1px solid var(--color-border);
}
.chain-node .node-glyph { width: 18px; height: 18px; border-radius: 4px; display: grid; place-items: center; font-size: 10px; color: white; font-weight: 600; }
.chain-arrow { color: var(--color-text-faint); font-size: 14px; }
```

Node-glyph background colors per source: `.asn = #1e40af`, `.ics = #7c3aed`,
`.paypal = #003087`, `.amz = #ff9900`, etc. — derived from each source's
brand color so the chain reads like a flow diagram.

## What to Avoid

- **Stacked single-column composition** (sketch 007 variant A) — works
  for one entity but makes the funding chain feel buried mid-page and
  forces the transaction list to compete with sidebar-style sections
  for attention.
- **Hero + 2-col body without tabs** (sketch 007 variant B) — left aside
  is too narrow at 320px to host both aliases + categories + recurring +
  funding-chain comfortably without scroll inside scroll.
- **Auto-discovering counterparties without a type** — the type taxonomy
  is load-bearing for the per-type variations above; an "untyped
  counterparty" UI doesn't exist.
- **Rendering a real profile for `self_account` types** — confuses
  routing-leg accounts with external counterparties. Always stub-redirect.
- **Exposing personal IBANs in lists, URLs, page titles, or exports** —
  the privacy-banner + hidden-default pattern is mandatory, not optional.
- **Showing Chains tab for non-merchant types** — even when empty. Better
  to drop the tab + add the tab-note ("— no funding chains for X") on the
  right of the tab bar so the user understands it's deliberate, not missing.

## Origin

Synthesized from sketches: 007, 008
Source files available in: `sources/007-phase-17-counterparty-profile-shape-merchant/`,
`sources/008-phase-17-counterparty-profile-type-variants/`
