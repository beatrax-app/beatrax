# The walk ledger

The [definition of done](https://github.com/beatrax-app/spec/blob/main/40-quality/definition-of-done.md)
asks that *"the behaviour was walked in a browser, on both desktop and phone
widths."* Nothing recorded that it happened, so an auditor reading this
repository could not tell **"never walked"** from **"walked and unrecorded"**.
Several release-manifest requirements came back `unprovable` for exactly that
reason — not because the behaviour was wrong, but because the evidence was
nowhere.

This page is where a walk is recorded. One row per requirement identifier, both
widths, a date, an outcome, and a path to the pixels.

## What a walk is

Four things, all of them. A capture that skips any one of them is not a walk.

1. **Both widths.** A desktop viewport and a real narrow one — 1440×900 and
   390×844 are what the rows below used. Viewport emulation is the right tool:
   the definition of done asks for a browser, not a device. Where a requirement
   genuinely needs hardware, the row says `device-needed` and stays open.
2. **The state, reached.** Several requirements are about what happens on an
   *action* — a conflict, a refusal, a restatement. Loading the page proves
   nothing about them. Seed the state or perform the action, and write down
   which you did.
3. **The screenshots, looked at.** Capturing is not inspecting. Every row below
   carries a note about what is actually on the screen — the text rendered,
   whether the element is present, whether it overflows, whether two things
   collide. A row that says "captured" and nothing else is worth nothing.
4. **The measurements, taken.** The eye misses a clipped child inside a clipped
   parent. See [what to measure](#what-to-measure).

## Standing up an instance to walk

**Never walk the owner's running app.** The desktop shell serves the real
ledger on `127.0.0.1:4000`; do not navigate it, and do not point a browser
driver at it. Stand up your own:

```sh
bin/worktree.sh <name>                       # ../wt-<name>, bootstrapped
cd ../wt-<name>
php artisan migrate --force                  # its own database/database.sqlite
php artisan demo:seed                        # 2 users, 7 accounts, ~700 rows
php artisan serve --host=127.0.0.1 --port=8477
```

Sign in as `demo-1` / `demo-only`. Check the port and that the data is the demo
set before the first screenshot. `4000`, `8100`, `51337` and `51338` are taken
by the desktop app and the sync daemon.

Two traps in this recipe:

- **`php artisan serve` uses `database/database.sqlite`; the desktop shell uses
  `database/nativephp.sqlite`.** They are different files in one checkout, so a
  row you seeded through `artisan` is invisible to a running shell and vice
  versa.
- **Do not force a mobile runtime by setting `NATIVEPHP_PLATFORM`.** It makes
  `UserDataPathService` resolve the durable store to a **sibling of the
  application root**, which in the worktree layout is a sibling of every
  worktree *and* of the main checkout. One run created
  `beatrax-app/persisted_data/`, and because `isMobileRuntime()` also answers
  true on `is_dir()` of that path, its mere existence flips every checkout —
  including the owner's running desktop app — into mobile mode. If you need a
  mobile runtime, use a phone build.

## What to measure

- **Horizontal overflow, per element.** `document.documentElement.scrollWidth`
  does not find a page too wide for the screen: the scroll often lives on
  `main`, and an overflowing child inside a clipped parent leaves the document
  width untouched. Compare each element's `getBoundingClientRect().right`
  against `window.innerWidth`, and `scrollWidth` against `clientWidth` on the
  element itself. The net-worth defect below reported `bodyScrollWidth === 390`
  while two rows ran 190px past the edge.
- **A 44px tap halo reads as overflow.** Controls carry an absolutely
  positioned `::after` at `max(100%, 44px)`, which counts toward `scrollWidth`.
  Exclude it before chasing a hit, and settle a tap target by hit-testing with
  `elementFromPoint`, never by geometry. A tap-target *width* measured in a
  desktop browser is meaningless: the 44px floor is a `pointer: coarse` rule.
- **A longer translation breaks a layout sooner than English does.** For any
  copy in a fixed-width box — a corner toast especially — sweep the locales and
  measure, rather than eyeballing English. The sweep below covered all 26.
- **Meaning carried by colour alone**, text truncated to the point of changing
  meaning, and a figure whose currency or sign is cut off.

## What must be recorded

For each identifier: the **surface** (the route and the control, not the
module), a result at **each** width, the **date**, an **outcome**, and where the
evidence lives. Outcomes are one of:

| Outcome | Means |
|---|---|
| `walked` | Both widths, the state reached, the screenshots inspected, nothing wrong found |
| `walked — defect` | Walked as above; something is wrong, and it is written down below |
| `not walked` | The state could not be reached from a browser. The row says why |
| `device-needed` | The only surface is a phone build's own runtime |
| `no surface` | The requirement renders nothing; there is nothing to walk |

Keep it to what you can justify. **An empty cell is better than an optimistic
one**, and `not walked` is a useful answer — a walk you did not do is not.

## Walked — v2.0.0 release manifest

Base `1d412d166`. Instance: `wt-walkledger`, `php artisan serve` on
`127.0.0.1:8477`, `demo:seed` data, signed in as `demo-1`. Widths 1440×900 and
390×844. Evidence: [`.docs/evidence/walks/2026-09-13-v2-manifest/`](../evidence/walks/2026-09-13-v2-manifest/).

| id | surface | desktop | phone | date | outcome | evidence |
|---|---|---|---|---|---|---|
| A3-R21 | Corner conflict toast, restated variant — `receipts.receipt-conflict-toast` in `x-core::corner-notices`, reached by importing a statement row that restates a stored one; both buttons pressed | yes | yes | 2026-09-13 | `walked` | `a3-r21-restated-toast-{en,de,nl}-{desktop,phone}.png`, `a3-r21-restated-toast-bg-{phone,narrow320}.png`, `a3-r21-after-use-new-value-desktop.png`, `a3-r21-receipt-toast-phone.png`, `a3-r21-after-keep-statement-phone.png`, `c1-dashboard-{desktop,phone}.png` |
| A3-R22 | `/imports/{id}/preview` — one row at status **Enriched** instead of a second transaction | yes | yes | 2026-09-13 | `walked — defect` | `a3-r22-import-preview-restatement-{desktop,phone}.png` |
| A3-R23 | `/transactions/{id}` after the restatement is confirmed — the stored row's dates move, the money is asked about | yes | yes | 2026-09-13 | `walked` | `a3-r23-restated-row-dates-{desktop,phone}.png` |
| A8-R26 | `/migrations/new` — a YNAB4 export whose `Budgeted` cell reads `€ 200,00`; the whole file is refused | yes | yes | 2026-09-13 | `walked` | `a8-r26-migration-refusal-{desktop,phone}.png` |
| C1-R15 | Dashboard net-worth card — an XPF account the rate table cannot price, listed and named, left out of the total | yes | yes | 2026-09-13 | `walked — defect` | `c1-r15-r16-networth-breakdown-{desktop,phone}.png` |
| C1-R16 | Dashboard net-worth card — a `paypal_funding` account absent from the count, the list and the total | yes | yes | 2026-09-13 | `walked` | `c1-r15-r16-networth-breakdown-{desktop,phone}.png` |
| C1-R17 | `/reports` `?from=` / `?to=` / `?period=` rails and `/reports/export` | yes | yes | 2026-09-13 | `walked — defect` | `c1-r17-period-{leapday,badpreset,export}-{desktop,phone}.png` |
| C1-R18 | `/reports/library` pin-cap banner on a fourth pin, and the dashboard's row of pinned mini-cards | yes | yes | 2026-09-13 | `walked — defect` | `c1-r18-pin-cap-{desktop,phone}.png`, `c1-r18-pinned-strip-{desktop,phone}.png`, `c1-r18-pinned-card-ticks-{desktop,phone}.png` |
| C7-R21 | `/reports` Monthly/Weekly toggle, and a saved report whose stored granularity reads `quarterly` | yes | yes | 2026-09-13 | `walked — defect` | `c7-r21-granularity-monthly-{desktop,phone}.png`, `c7-r21-granularity-weekly-desktop.png`, `c7-r21-granularity-weekly-table-phone.png`, `c7-r21-stored-granularity-unreadable-{desktop,phone}.png` |
| C8-R22 | `/settings` digest-cadence select — the three-member set, changed and read back at each width | yes | yes | 2026-09-13 | `walked` | `c8-r22-digest-cadence-{desktop,phone}.png` |
| D1-R29 | `/budgets` envelope row — the amber marker and the sentence naming the currency that could not be priced | yes | yes | 2026-09-13 | `walked` | `d1-r29-budgets-unconverted-{desktop,phone}.png` |
| E2-R23 | Pairing-flow modal refusal on `/data-devices` | — | — | 2026-09-13 | `not walked` | — |

**E2-R23 — why not.** `PairingAnswerability::canBeAnswered()` returns true for
every non-mobile runtime, so the refusal branch is unreachable from a desktop
browser at any width. The only local way to force a mobile runtime writes the
durable store into the shared parent of every worktree — see the warning under
[standing up an instance](#standing-up-an-instance-to-walk). Reaching it needs a
phone build with the relay endpoint empty.

### How each state was reached

- **A3-R21 / A3-R22 / A3-R23** — imported
  `tests/fixtures/asn-a-card-row-at-the-terminals-price.csv` through
  `/imports/new` (ASN preset) and confirmed it, then imported
  `asn-the-same-card-row-restated-with-the-tip.csv`, which carries the same
  `Volgnummer` two days later at a different amount.
- **A8-R26** — built a YNAB4 export by hand from the register and budget headers
  the parser expects, with one `Budgeted` cell written as `€ 200,00`, zipped and
  uploaded.
- **C1-R15 / C1-R16 / D1-R29** — seeded two accounts the demo set has no
  equivalent of: `Tahiti Cash` (`cash`, XPF — absent from the bundled 30-code
  rate snapshot) carrying an opening credit and one categorised spend, and
  `PayPal funding` (`paypal_funding`, EUR) carrying €2,500.
- **C1-R17** — navigated the rails directly with `2027-02-29`, `2026-1-5`,
  `tomorrow` and `since_the_dawn_of_time`.
- **C1-R18** — saved a fourth report from the builder, pinned a third through
  the library to reach the cap, then pressed Pin on the fourth.
- **C7-R21** — pressed the toggle and read the URL and the buckets back; then
  rewrote one saved report's stored `granularity` to `quarterly` and opened it.
- **C8-R22** — changed the select and saved at each width, reloading to read the
  stored value back.

### What the screenshots show

- **A3-R21.** The restated toast reads *"Your bank restated this transaction."*
  over *"A later statement records a different amount ("-€14.99") than the row
  already stored ("-€12.99")"*, with **Use the new value** and **Keep the stored
  value**. At 390 the toast is 358px wide inside a 390px viewport, both buttons
  on one row, nothing clipped. The two-action-toast-in-a-corner risk **did not
  materialise**: sweeping all 26 locales at 390 gave `scrollWidth ===
  clientWidth` on the button row every time and no element past the viewport.
  Seventeen of the 26 have **zero** slack — the labels wrap inside the buttons
  and the row fills exactly — so the layout is at its limit but not over it.
  German, Bulgarian and Ukrainian still render fully at 320. Pressing
  **Use the new value**
  moved the stored amount to −€14.99 and offered the next pending conflict;
  pressing **Keep statement** on that one cleared the region.
- **A3-R22.** One row, `19/02/2026 · Café Plein · −€14.99 · Enriched`. The
  desktop table and the phone card list both fit. See the defect below.
- **A3-R23.** The transaction detail reads **19 Feb 2026** — the restating row's
  date, adopted without being asked — while *Amount (native)* still reads
  −€12.99 and the toast asks about that one field. Both halves of the
  requirement are on one screen.
- **A8-R26.** A rose `role="alert"` banner: *"This doesn't look like a YNAB4,
  nYNAB, or Actual export we can read."* All seven migration tables stayed at
  zero rows; the refused file, column and value went to the log and not to the
  screen.
- **C1-R15 / C1-R16.** *"across 6 accounts · Tahiti Cash not converted — no rate
  available"* in amber, and a breakdown line reading `Tahiti Cash · CFPF 472,600
  · no rate available`. The €2,500 `paypal_funding` account appears in neither
  the count, the list nor the €6,135.74 total.
- **C1-R17.** `?from=2027-02-29`, `2026-1-5` and `tomorrow` each render the
  builder with *"Use a valid date in YYYY-MM-DD form."* in place of the report —
  HTTP 200, no 500. `/reports/export` with the same date answers **422** with
  the same sentence.
- **C1-R18.** *"You can pin up to 3 reports. Unpin one to add this."* in rose,
  with a dismiss control; the header still reads *"4 saved reports · 3 of 3
  pinned"*. The dashboard draws exactly three mini-cards at both widths.
- **C7-R21.** Pressing **Weekly** rewrites the URL to `?gran=weekly`, flips
  `aria-pressed`, and regroups the buckets to `1 Sep / 8 Sep / 15 Sep / 22 Sep /
  29 Sep`. A saved report carrying `granularity: "quarterly"` opens with
  **Monthly** pressed, months in the buckets, no error, and no `gran` in the
  URL it rewrites itself to — the stored word is discarded rather than
  round-tripped.
- **C8-R22.** *"Your position"* offering exactly **Daily**, **Weekly**, **Off**.
  Set to Off at 1440 and Daily at 390; both persisted across a reload. A raw
  `UPDATE` to `monthly` is refused by the column's trigger.
- **D1-R29.** The `Eating out` row carries a 6px amber dot **and**, on the same
  row at both widths, the sentence *"· XPF not converted — no rate available"*.
  The XPF spend is absent from the €143.74 the row reports. Meaning is not
  carried by the dot alone.

## Relayed — walked by the E5/E6/F1/F3/G7 manifest audit

The same day's audit of those identifiers walked its own surfaces against an
isolated instance on `127.0.0.1:8391`. Those walks are recorded here so the
record survives the scratchpad they were written in. **They are that pass's
walks, not this one's**; the screenshots were re-opened and read before being
copied in, and the notes below describe what is in them.

| id | surface | desktop | phone | date | outcome | evidence |
|---|---|---|---|---|---|---|
| E5-R27 | `/mobile/setup/done` with a seeded progress cursor and one unverifiable withheld author | yes | yes | 2026-09-13 | `walked` (relayed) | `relayed-e5e6f-g7-synccomplete-phone.png` |
| E5-R28 | The phone's biometric enrolment affordance | — | — | 2026-09-13 | `device-needed` | — |
| E6-R13 | `/data-devices` top line, with and without a `sync_withheld_history` row | yes | yes | 2026-09-13 | `walked` (relayed) | `relayed-e5e6f-g7-withheld-{desktop,phone}.png` |
| E6-R14 | `/data-devices` status ladder — hold against behind, and against offline | yes | yes | 2026-09-13 | `walked` (relayed) | — |
| E6-R15 | `/data-devices` aggregate line and per-peer block clearing together | yes | yes | 2026-09-13 | `walked` (relayed) | — |
| E6-R16 | `/data-devices` "Held back by another device" block — no action control | yes | yes | 2026-09-13 | `walked` (relayed) | `relayed-e5e6f-g7-heldback-{desktop,phone}.png` |
| F1-R18 | Lock on window close, desktop shell only | yes | n/a | 2026-09-13 | `walked` (relayed) | `relayed-e5e6f-g7-lockonclose-desktop.png` |
| G7-R16 | `/settings` time-zone control, deferring option and a stored choice | yes | yes | 2026-09-13 | `walked` (relayed) | `relayed-e5e6f-g7-tz-phone.png` |
| G7-R17 | `/settings` time zone written per installation | yes | — | 2026-09-13 | `walked` (relayed, install half) | — |
| G7-R18 | `/settings` deferring option naming the machine's own zone, and the way back to it | yes | yes | 2026-09-13 | `walked` (relayed) | `relayed-e5e6f-g7-tz-phone.png` |
| E5-R26 | — | — | — | — | `no surface` | — |
| F3-R37 | — | — | — | — | `no surface` | — |

Read in those screenshots: the held-back block at 390 carries a heading, an
explainer that names a **condition** rather than an act, *"155 changes signed by
old-phone"* and *"Held by the-mac"*, with no control inside it — E6-R16's whole
claim. The time-zone card at 390 reads *"Time zone for this installation"* over
*"This machine (Europe/Amsterdam)"* with the help line naming what it decides.
The sync-complete screen carries *"155 changes have not arrived yet."* in an
informational block, under a heading that reads **"This device is synced"** —
see the last finding below.

## Defects the walking found

Each is recorded here with the width it appeared at, and nothing was fixed in
the walk that found them: a fix smuggled into a walk makes both unreadable.

**All seven are fixed**, in a pull request of their own. Three of the seven were
one defect wearing two faces or found on the wrong surface, which is why there
are five fixes below rather than seven, and what each one was re-measured
against is written under it.

| # | Fixed by | Re-measured |
|---|---|---|
| 1 | The disclosure sentence moved out of the `shrink-0` figure it was growing inside | Name span 0px → 234px, figure right edge 569px/580px → 349px in a 390px viewport, `li.scrollWidth === li.clientWidth` |
| 2 | `ReportGroupHeading::label()` takes the granularity, and it is a required argument | `Week` over 1 Sep … 29 Sep; `Month` still over months |
| 3 + 6 | One defect: the report page's refusal surface. The `?period=` rail now reaches it, and it is drawn as a refusal | No report drawn, the danger-tone alert the app refuses with, `aria-invalid` on the control that caused it |
| 4 | The preview reads `EnrichedDisposition::conflictingFields` instead of `source_ref` alone | `amount_minor: −€12.99 → −€14.99` in place of `source_ref: 4471902 → 4471902` |
| 5 | `beatraxFitAxisLabels` measures the drawn boxes, for every chart rather than the card the walk found it on | Overlaps 72px/10px/10px/85px → **0**, first tick clipped 6–7px → **0** |
| 7 | The heading answers for the withheld count beside it | `This device is set up` over `155 changes have not arrived yet.` |

Two of the seven were not what the row above them said. **#5 is not the pinned
card's defect**: the same chart on `/reports` overlapped `Kappabashi Dougu` and
`ANA` by 85px at 390 and had never carried an override of its own — the card was
where the walk looked, not where the defect lived. And **#6 did carry
`role="alert"`** when it was re-read; what it did not carry was a tone, and it
wore `srch-no-results` — literally the empty state two branches below it in the
same template — with `aria-live="polite"` overriding its own role.

### 1. The net-worth breakdown loses the account name and runs off the screen (phone)

**C1-R15 / C1-R16 surface. 390×844, English.** In the expanded breakdown, a line
for an account whose balance had to be **converted** renders with its name span
squeezed to **0px wide** and its figure running past the viewport:

| line | name span | figure span right edge | viewport |
|---|---|---|---|
| Japan Trip Card | 0px | 569px | 390px |
| Japan Trip Cash | 0px | 580px | 390px |

The reader sees `¥112,600 ≈ €707.73 rates as of 05/06/2026 (3 month…` with no
account name at all. Unconverted lines (ASN Bank, PayPal, and C1-R15's own
`Tahiti Cash · no rate available`) are unaffected, and 1440 is clean. Note that
`document.body.scrollWidth` reads **390** throughout — a clipped ancestor hides
it, which is why the per-element measurement is the one that matters. Evidence:
`c1-r15-r16-networth-breakdown-phone.png`.

### 2. A weekly report heads its column "Month" (both widths)

**C7-R21 surface.** With **Weekly** selected and the buckets correctly grouped
as `1 Sep 2026 … 29 Sep 2026`, the first column header reads **MONTH**.
`ReportGroupHeading::for()` answers `Period` for a time-bucket dimension, and
`Period->label()` reads the `group_header.month` key — the heading takes no
account of the granularity at all, in any of the 26 locales. Evidence:
`c7-r21-granularity-weekly-desktop.png`,
`c7-r21-granularity-weekly-table-phone.png`.

### 3. An unknown `?period=` draws a report with no period selected (both widths)

**C1-R17 surface.** `/reports?period=since_the_dawn_of_time` returns 200, draws
a full report over the default period, keeps the unusable value in the URL, and
leaves **every** button in the Period group unpressed — `This month` through
`Custom range`, none selected. No message is shown. The reader is given a
€2,155.13 total and no indication of what period it covers. The `from`/`to`
rails beside it *are* refused with a message, so the two halves of the same
control behave differently. Evidence: `c1-r17-period-badpreset-desktop.png`.

### 4. The restatement preview names the one field that did not change (both widths)

**A3-R22 surface.** An enriched preview row prints `source_ref: 4471902 →
4471902` — an unchanged value drawn as a change — and says nothing about the
amount, which is the field that actually disagrees (−€12.99 → −€14.99). The
detail line in `preview-wizard.blade.php` is hard-coded to `source_ref`, which
reads correctly for the original enrichment case (`∅ → 4471902`) but cannot be
right for a restatement, where the reference is matched **on** and therefore
identical by construction. Evidence:
`a3-r22-import-preview-restatement-desktop.png`.

### 5. Pinned mini-card axis labels overprint each other (both widths)

**C1-R18 surface.** A pinned report grouped by counterparty draws x-axis ticks
that overlap:

| width | colliding pair | overlap |
|---|---|---|
| 1440 | `Lidl` over `Domino's Pizza` | 26px |
| 390 | `Vesteda` over `Albert Heijn` | 8px |
| 390 | `ANA` over `Yamada Denki` | 19px |

Desktop renders the result as `Dbirdlo's Pizza`. The first tick also loses its
leading glyph at the plot edge — `Vesteda` draws as `'esteda` at both widths.
The card's own comment records that `trim: false` was set so that ticks are
complete and colliding ones hidden; neither is happening here. Evidence:
`c1-r18-pinned-card-ticks-{desktop,phone}.png`.

### 6. The period refusal is styled as an empty state (both widths)

**C1-R17 surface.** *"Use a valid date in YYYY-MM-DD form."* renders in the
ordinary body colour with no `role="alert"` and no error styling, under a plain
`Period` heading, and the control it refers to still displays the impossible
date (`29/02/2027`). Nothing announces the refusal to a screen reader, and by
eye it reads as "there is nothing here" rather than "what you asked for was
rejected". Evidence: `c1-r17-period-leapday-{desktop,phone}.png`.

### 7. "This device is synced" over 155 changes that have not arrived (phone)

**E5-R27's surface, read from the relayed screenshot** — reported for the
manifest owner rather than re-audited here. The sync-complete screen states the
withheld count and its condition exactly as the requirement asks, but the
heading above it reads **"This device is synced"**. The count is the fix for a
first sync reporting a whole history it does not have; the heading still reports
one. Evidence: `relayed-e5e6f-g7-synccomplete-phone.png`.

## Adding a row

Walk it, then add the row in the same pull request as the behaviour. The
evidence directory is dated and per-manifest
(`.docs/evidence/walks/<date>-<what>/`) so a later walk of the same identifier
adds a row rather than overwriting one. `.docs/` is excluded from every shipped
bundle (`config/nativephp.php`), so screenshots here never reach a build —
unlike `storage/app`, which does.
