# Community Merchant Identification

A crowd-sourced corpus of merchant-pattern mappings (YAML in the
diederik repo) so users worldwide benefit from each other's
identifications of cryptic transaction strings (`BCK*SHELL PIETER
NIEUW`, `CCV*KIOSK 7438`, `EUR/IDEAL/00045-...`). The surface has
three layers, all sharing one suggest-mapping modal.

## Design Decisions

### Three-layer surface architecture (sketch 005, combined A+B+C)

| Layer | Surface | Role | Primary affordance |
|---|---|---|---|
| **B** | `/triage` (existing) | Moment-of-use entry | Dashed "❋ Help others identify this" button per mystery-code row |
| **A** | `/community/mystery-merchants` (new) | Browse-all destination | Cards per mystery code with "Suggest a name" primary CTA |
| **C** | `Settings → Shared merchant list` (new) | Preferences | Three toggles + corpus stats + recent-contributions widget |

All three open the **same shared modal**. The user contributes
without ever leaving the app and without ever seeing GitHub.

### Triage row CTA (sketch 005, variant B — primary)

Lives in the existing `/triage` (uncategorized) workflow as the
primary contribution surface.

- **Position:** every mystery-code row already has a "Rename &
  categorise" button (the local fix). The community CTA sits as a
  *secondary* button next to it.
- **Button styling:** quieter than the primary action — dashed
  border (`1px dashed var(--color-border-strong)`), transparent
  background, `var(--color-text-muted)` text, `❋` (eight-pointed
  asterisk) glyph prefix. Hover: solid border, full-strength text.
- **Copy:** "❋ Help others identify this" — invitational, not
  demanding. Tooltip clarifies: "Anonymously share a mapping for
  this code so other diederik users benefit".
- **Trigger:** opens the shared suggest-mapping modal (see below).
- **Affordance visibility:** opacity 0 → 1 on row hover, paired
  with the local rename button so they appear together.
- **Empty-state celebration:** below the table, a small line —
  "You've helped identify **23 merchants** in the shared list.
  Thanks 🙏" with a link to "Browse all mystery codes →" (routes
  to layer A).

### `/community/mystery-merchants` destination page (variant A)

A first-class destination in the sidebar under Categorization with
a count badge for unidentified codes.

- **Stats strip (4 stats):**
  - Mystery codes in your data
  - Mappings in shared list
  - % of your imports auto-named
  - Your contributions live
- **Card list of mystery codes from your data**, sorted by frequency
  × recency. Each card:
  - Monospace code chip + a one-line "Likely:" hint inferred from
    payment-type + amount range + counterparty IBAN.
  - Last-seen date + "saw it N times over M months".
  - Payment-type hint + typical-amount.
  - "Seen by N other diederik installs" social proof (when corpus
    can match the pattern).
  - Primary `[Suggest a name →]` CTA + ghost `[Skip — show in 30
    days]`.
- **"About the shared list" section** at the bottom — one-paragraph
  explainer + corpus card showing mapping count, contributor count,
  supported banks, last update date, "View on GitHub →" link.

### Settings section + widget (variant C)

`Settings → Shared merchant list` — the preferences and
"find-this-later" entry.

- **Two-column layout** inside a settings-section card:
  - Left rail (`meta-side` with subtle bg): "About the shared
    list" explainer + "View the file on GitHub →" power-user link.
  - Right rail (`body-side`): stats (3) + three toggle rows + a
    "Browse mystery merchants" CTA + "Open file in editor" power-
    user button.
- **The three toggles:**
  1. **Use the shared merchant list** (on by default) — auto-name
     transactions using community mappings.
  2. **Offer to contribute your unidentified merchants** (on by
     default) — controls visibility of the layer-B "Help others
     identify this" button.
  3. **Update the shared list on app updates** (on by default) —
     pull new mappings into local cache when the app updates.
- **Recent-community-contributions widget** at the bottom — corpus
  card with last 30 days' contribution count, new banks added,
  last release version + date.

### The shared suggest-mapping modal (used by all three layers)

The hardest UX problem is hiding the "this is a PR to a YAML file
in a Git repo" awkwardness from non-technical users. The modal
does it like this:

- **Title:** "Suggest a name for this code".
- **Sub:** "Goes into the shared list as a draft pull request. We
  strip everything except the code and the name — no amounts, no
  dates, no account info." (Explicit privacy contract.)
- **Field 1 — Mystery code:** read-only, monospace, pre-filled with
  the pattern. The user can see exactly what's being submitted.
- **Field 2 — Human-friendly merchant name:** text input, the only
  thing the user types.
- **Field 3 — Category hint (optional):** dropdown of standard
  categories.
- **Field 4 — Region (optional):** dropdown defaulting to NL with
  global / BE / DE alternatives. Acknowledges the corpus needs to
  scale beyond NL.
- **YAML preview panel** below the fields:
  - Mono-font box showing exactly what gets serialized to the
    repo's `merchant-mappings.yaml`.
  - Syntax-coloured (key blue, string emerald, comment faint).
  - Updates live as the user types.
  - Demystifies the technical artifact without forcing them to
    understand it.
- **Footer note:** "Submits as a draft PR from a **diederik-bot**
  account. You're not named anywhere unless you choose to be."
  (Privacy + anonymity reassurance.)
- **Actions:** `[Cancel]` ghost + `[Submit as draft PR]` primary.

## CSS Patterns

### Triage row + help-others CTA

```css
.triage-row {
  display: grid; grid-template-columns: 88px 1.4fr 1fr auto;
  gap: 16px; align-items: center;
  padding: 14px 16px;
  border-top: 1px solid var(--color-border);
  font-size: var(--text-sm);
}
.triage-row .desc {
  font-family: var(--font-mono); font-size: var(--text-sm);
  color: var(--color-text-muted); font-style: italic;
}
.triage-row .row-cta {
  display: flex; gap: 8px;
  opacity: 0; transition: opacity 120ms ease;
}
.triage-row:hover .row-cta { opacity: 1; }

.help-others-link {
  font-size: var(--text-xs); color: var(--color-text-muted);
  display: inline-flex; align-items: center; gap: 4px;
  padding: 4px 8px; border-radius: var(--radius-sm);
  cursor: pointer; transition: var(--tx-quick);
  border: 1px dashed var(--color-border-strong);
  background: transparent;
}
.help-others-link:hover {
  color: var(--color-text); border-color: var(--color-text);
  border-style: solid;
}
```

### Mystery-merchant card (destination page)

```css
.mystery {
  background: var(--color-surface); border: 1px solid var(--color-border);
  border-radius: var(--radius-md); padding: 14px 16px;
  display: grid; grid-template-columns: 1.4fr 1fr 1fr auto; gap: 16px;
  align-items: center;
}
.mystery code {
  font-family: var(--font-mono); font-size: var(--text-sm);
  color: var(--color-text);
  background: var(--color-surface-2); padding: 3px 8px; border-radius: var(--radius-sm);
}
```

### Suggest-mapping modal

```css
.modal-backdrop {
  position: fixed; inset: 0;
  background: rgba(15, 23, 42, 0.45);
  z-index: 100;
  display: none; align-items: center; justify-content: center;
}
.modal-backdrop.open { display: flex; }
.modal {
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  box-shadow: var(--shadow-lg);
  width: 540px; max-width: calc(100vw - 32px);
  padding: 24px 26px;
}
.modal .yaml-preview {
  font-family: var(--font-mono); font-size: var(--text-xs);
  background: var(--color-bg-subtle); border: 1px solid var(--color-border);
  border-radius: var(--radius-sm); padding: 12px 14px;
  color: var(--color-text); margin-top: 10px;
  white-space: pre; overflow-x: auto;
}
.modal .yaml-preview .k { color: var(--color-blue); }    /* key */
.modal .yaml-preview .s { color: var(--color-emerald); } /* string */
.modal .yaml-preview .c { color: var(--color-text-faint); } /* comment */
```

### Settings section with toggle rows

```css
.settings-section {
  background: var(--color-surface); border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  display: grid; grid-template-columns: 280px 1fr;
  overflow: hidden;
}
.settings-section .meta-side {
  padding: 22px;
  background: var(--color-bg-subtle);
  border-right: 1px solid var(--color-border);
}
.settings-section .body-side { padding: 22px; }

.toggle-row {
  display: flex; justify-content: space-between; align-items: center;
  padding: 12px 0; border-top: 1px solid var(--color-border);
}
.toggle-row:first-of-type { border-top: none; }
.toggle {
  width: 36px; height: 20px; border-radius: 999px;
  background: var(--color-emerald); position: relative;
  flex: 0 0 36px;
}
.toggle::after {
  content: ''; position: absolute; top: 2px; left: 18px;
  width: 16px; height: 16px; border-radius: 50%; background: white;
  transition: var(--tx-quick);
  box-shadow: 0 1px 2px rgba(0,0,0,0.2);
}
.toggle.off { background: var(--color-border-strong); }
.toggle.off::after { left: 2px; }
```

## HTML Structures

### Triage row with both local + community CTAs

```html
<div class="triage-row">
  <span class="date">May 19</span>
  <span class="desc">BCK*SHELL PIETER NIEUW</span>
  <span class="seen-count">
    Seen <strong>9×</strong> · <span style="color:#7c3aed;">always PIN</span> · ~€42
  </span>
  <div class="row-cta">
    <button class="pill-btn">Rename &amp; categorise</button>
    <button class="help-others-link" onclick="openSuggestModal('BCK*SHELL PIETER NIEUW')">
      ❋ Help others identify this
    </button>
  </div>
</div>
```

### Suggest-mapping modal

```html
<div class="modal-backdrop" id="suggest-modal">
  <div class="modal">
    <h2>Suggest a name for this code</h2>
    <p class="sub">
      Goes into the shared list as a draft pull request. We strip
      everything except the code and the name — no amounts, no dates,
      no account info.
    </p>

    <div class="field">
      <label class="field-label">Mystery code (from your statement)</label>
      <input type="text" id="modal-pattern" value="BCK*SHELL PIETER NIEUW" readonly>
    </div>

    <div class="field">
      <label class="field-label">Human-friendly merchant name</label>
      <input type="text" id="modal-name" placeholder="e.g. Shell — Pieter Nieuwlandstraat">
    </div>

    <div class="field" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
      <div>
        <label class="field-label">Category hint (optional)</label>
        <select id="modal-cat"><!-- options --></select>
      </div>
      <div>
        <label class="field-label">Region (optional)</label>
        <select id="modal-region"><!-- options --></select>
      </div>
    </div>

    <div>
      <label class="field-label">What gets sent (preview)</label>
      <pre class="yaml-preview" id="yaml-preview"></pre>
    </div>

    <div class="modal-foot">
      <div class="note">
        Submits as a draft PR from a <strong>diederik-bot</strong> account.
        You're not named anywhere unless you choose to be.
      </div>
      <div class="actions">
        <button class="pill-btn ghost">Cancel</button>
        <button class="pill-btn primary">Submit as draft PR</button>
      </div>
    </div>
  </div>
</div>
```

### Settings toggles

```html
<div class="settings-section">
  <div class="meta-side">
    <h3>About the shared list</h3>
    <p>A YAML file in the diederik repo — every entry is a pattern → name pair...</p>
    <p><a href="#">View the file on GitHub →</a></p>
  </div>

  <div class="body-side">
    <div class="settings-stats">
      <div class="stat"><div class="num">1,247</div><div class="lbl">Mappings in shared list</div></div>
      <div class="stat"><div class="num">82%</div><div class="lbl">Of your imports auto-named</div></div>
      <div class="stat"><div class="num">23</div><div class="lbl">Of your contributions live</div></div>
    </div>

    <div class="toggle-row">
      <div class="tr-label">Use the shared merchant list
        <div class="sub">Auto-name your transactions using community-contributed mappings.</div>
      </div>
      <div class="toggle"></div>
    </div>
    <!-- more toggle rows -->
  </div>
</div>
```

## What to Avoid

- **Don't put the community CTA in only one place.** It needs the
  three-layer architecture (moment-of-use + destination +
  preferences) because each layer catches a different mindset.
- **Don't surface raw GitHub URLs in the user flow** (sketches'
  power-user "View on GitHub →" affordances are intentionally
  quiet). The whole point is the contributor doesn't need to know
  GitHub exists.
- **Don't make the modal default-submit non-anonymously.** The
  privacy contract is "you're anonymous unless you choose to be" —
  any future "include your name as contributor" feature must be
  explicit opt-in.
- **Don't include the user's transaction amounts, dates, or account
  IDs in the YAML preview** even by accident. Only `pattern` +
  `name` + optional `category` + optional `region` ship.
- **Don't gate the corpus contribution on user account creation
  with diederik servers.** There are no diederik servers — the bot
  account is a GitHub-app abstraction that lets the contribution
  flow through without users authenticating to anything.
- **Don't auto-merge contributions.** The PR is *draft*; a
  maintainer reviews. This guards against bad-faith mappings
  (someone naming a competitor merchant something rude, etc.).

## Origin

Synthesized from sketch: 005 (crowd-sourced merchant identification).

Source files: `sources/005-phase-16-1-crowd-merchant/`.
