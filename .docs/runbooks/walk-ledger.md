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
390×844. Evidence: captured as `2026-09-13-v2-manifest/`, kept off this
repository — see **Adding a row**. The file names in the last column are the
index to it.

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
phone build with the relay endpoint empty. **Since walked on both handsets** —
see [Walked on device](#walked-on-device--e2-r23-e5-r28); this row stays as it
was written.

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

**E5-R28 has since been walked on both handsets** — see
[Walked on device](#walked-on-device--e2-r23-e5-r28). The `device-needed` row
above stays as it was written.

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

## Walked on device — E2-R23, E5-R28

Two rows above stood open because their only surface is a phone build's own
runtime. Both were walked on hardware on 2026-09-13, on a Galaxy A51 and an
iPhone 12 mini.

**The build, and why it is named here.** `efaa611dd` — `origin/main` at the
time — built from `wt-phonewalk` as `2.0.0` / versionCode `20000`.
`native:install --with-icu`, then `scripts/nativephp_patch_all.php`, then
`native:run` per platform. **Uninstall first on both**: the A51 reports
`firstInstallTime == lastUpdateTime`, and the iPhone went from the shipped
`1.3.0` to `2.0.0`. The deployed PHP was read back off each device before
anything was pressed — `Modules/Auth/Internal/Lock/ColdStartEnroller.php`
present and `ColdStartEnrollmentService.php` absent in
`app_storage/laravel/` (Android) and `Documents/app/` (iOS) — so neither walk
ran against the tree an earlier device run exercised.

The staleness check for this run, against the paths it exercised:

```sh
git diff --stat efaa611dd origin/main -- \
  Modules/Sync/Internal/Pairing/PairingAnswerability.php \
  Modules/Sync/Internal/Http/Livewire/PairingFlowModal.php \
  Modules/Sync/Resources/views/livewire/pairing-flow-modal.blade.php \
  Modules/Sync/Resources/lang/en/pairing.php \
  Modules/Mobile/Internal/Http/Livewire/MobilePairingScan.php \
  Modules/Mobile/Internal/Http/Livewire/MobileLockScreen.php \
  Modules/Mobile/Internal/Identity/ \
  Modules/Mobile/Resources/views/livewire/mobile-lock-screen.blade.php \
  Modules/Auth/Public/Http/Livewire/AppLockSettingsSection.php \
  Modules/Auth/Public/Http/Livewire/Concerns/ManagesBiometricEnrollment.php \
  Modules/Auth/Resources/views/livewire/app-lock-settings-section.blade.php \
  mobile-app/nativephp-plugins/biometric-vault/
```

Empty against `origin/main` at `57cbc74f2`, three commits later. The three
commits in between touch reports, the dashboard, the desktop bundle and the
Android permission checker, and none of the paths above.

**The instruments.** The A51 over CDP (`adb forward` to
`webview_devtools_remote_<pid>`), the iPhone over the WebKit inspector proxy.
Screenshots are the device's own: `adb exec-out screencap` on Android, and on
iOS `Page.snapshotRect` through the inspector, because `idevicescreenshot`
refuses without a mounted developer disk image. Neither handset was paired to
anything; the owner's desktop ledger on `127.0.0.1:4000` was not navigated,
dialled or paired against.

| id | surface | Android | iPhone | date | outcome | evidence |
|---|---|---|---|---|---|---|
| E2-R23 | `sync.pairing-flow-modal` on `/data-devices`, **Show my code** pressed with no `relay.json` on the device | yes | yes | 2026-09-13 | `walked — defect` | `e2-r23-choose-direction-android.png`, `e2-r23-refusal-{android,ios}.png`, `e2-r23-named-direction-{android,ios}.png` |
| E5-R28 | The biometric row in `auth.app-lock-settings-section` on `/data-devices`, and the `Use fingerprint` trigger on `/mobile/lock` | yes | yes | 2026-09-13 | `walked — defect` | `e5-r28-affordance-offered-{android,ios}.png`, `e5-r28-enroll-prompt-android.png`, `e5-r28-enroll-refusal-ios.png`, `e5-r28-lockscreen-affordance-android.png`, `e5-r28-{android-capability-answer,android-biometricprompt-focus,ios-capability-refusal}.txt` |
| G7-R17 | Cross-device propagation half | — | — | 2026-09-13 | `not walked` | — |

**G7-R17 — why not.** The install half is already recorded above. The
propagation half needs two paired devices, and these two handsets cannot pair
to each other: only one side of a pairing listens, `sync:serve` runs under
`SyncListenerProcess` and needs `Native\Desktop\Facades\ChildProcess`, so
neither phone can be the side that is answered. Pairing them would need a
relay standing between them and a camera pointed at the other's QR, which is
what E2-R23's refusal says in as many words. The only listening device on this
network is the owner's desktop app, serving the real ledger, which this pass
was told not to pair against. Reaching it needs a second instance standing up
its own credentialed `sync:serve`.

### How each state was reached

Both handsets were taken through the same setup by action, not by seeding:
welcome screen → **Create account** → the recovery-code screen, acknowledged →
`/data-devices` → an app-lock PIN set with the account password → **Enable
encryption** → the **Enable sync** switch, which is what makes the
**Pair a new device** control exist at all.

- **E2-R23** — opened the pairing modal from that control and pressed
  **Show my code**. The precondition is the out-of-box one and was checked
  rather than assumed: `app_storage/persisted_data/sync/relay.json` does not
  exist on the A51, and the relay-endpoint field on the page behind the modal
  is empty, showing its `https://relay.example.com` placeholder. Then pressed
  **Enter a code**, which is the direction the refusal names, to confirm that
  road exists.
- **E5-R28** — read the biometric row on `/data-devices` at the moment the app
  lock came on, then pressed **Enroll**. On the A51 that took the OS-vault arm
  (a PIN box headed *"Turn on biometric unlock — confirm with PIN"*), and on
  the iPhone it refused. The A51 was then idled out to `/mobile/lock` with the
  auto-lock set to one minute and cold-started, so that the lock screen's own
  `x-init` trigger fired rather than being tapped.

### What the screenshots show

- **E2-R23, both handsets.** The modal reads *"Pair a new device · Step 1 of
  3"* over two cards, **Show my code** (*"Show this device's code for the
  other device to read."*) and **Enter a code** (*"Type the code shown on the
  other device."*). Pressing the first puts a rose `role="alert"` line above
  both of them: *"A code shown here could not be answered: this device cannot
  be reached over the network, and no relay is set up. Show the other device's
  code and enter that here instead."* Nothing clips — at 411 CSS px on the A51
  the alert is 298×109 with its right edge at 355, and at 375 on the iPhone it
  is 262×95 with its right edge at 319. `pairing_tokens` on the A51 holds
  **zero rows** after the press, so the ceremony really is refused before a
  token is minted rather than after.
- **E2-R23, the named direction.** **Enter a code** lands on `/mobile/pair` on
  both: a scan frame reading *"The camera is off. Open it to scan the code
  shown on your other device."* over **Open the camera**, **Enter code
  instead** and **Cancel**. The iPhone adds a paragraph the A51 does not —
  *"Searching the network for the other device does not work on iPhone yet, so
  a typed code cannot find it on its own. Scan the code with the camera
  instead…"* — so the road the refusal points at names its own limit on the
  platform that has one.
- **E5-R28, A51.** The App-lock section renders *"Use fingerprint"* over
  *"Enroll this device to unlock with biometrics."* with an **Enroll** button.
  That offer is the platform's own answer: `window.PublicKeyCredential` is
  `undefined` in this WebView, so the row's client probe cannot have set it,
  and the server-rendered Livewire snapshot already carries
  `biometricCapable: true`. logcat shows why — PHP calls the bridge and
  Android answers `canAuthenticate(STRONG)=0 (available)`; the device has one
  fingerprint enrolled, registered at 19:37 the same day. Enrolment succeeded
  (the row becomes *"This device is enrolled for biometric unlock."* with
  **Remove**, and `user_app_lock_configs.cold_start_biometric_enrolled` reads
  `1`), and no refusal was logged, which is correct because nothing refused.
- **E5-R28, the A51 lock screen.** After a cold start the screen carries the
  Beatrax mark, ten PIN dots, the keypad, and an accent-filled **Use
  fingerprint** button below it, with *"Forgot your PIN? Sign out"* under that.
  The trigger is not decorative: at the moment it fired,
  `dumpsys window` reported `mCurrentFocus=Window{BiometricPrompt}` — the
  system prompt, raised by the app without a tap — and keystore2 logged the
  enclave operation behind it. The prompt's window is secure, so `screencap`
  returns an empty file; that is why the focus line is in the evidence beside
  the screenshot of the screen underneath it.
- **E5-R28, iPhone.** The same row renders *"Use Face ID"* with an **Enroll**
  button — and pressing it answers, in rose, *"This version of Beatrax has
  nowhere to store an unlock key, so biometric unlock is not offered. Your
  device is not the limitation."* Both are in one frame: the sentence saying
  biometric unlock is not offered, printed directly above the control offering
  it. See the finding below.

### What the device walking found

Numbered on from the seven above, so a reference stays unique.

**All three are fixed**, in a pull request of their own, and all three are one
shape: an affordance drawn as an ordinary enabled control, refusing only once it
is pressed.

| # | Fixed by | Held by |
|---|---|---|
| 8 | The step that draws the two cards asks `PairingAnswerability` before it draws them, rather than `showMyCode()` asking after the offer | The card is disabled and the sentence naming the other direction stands before the press; the guard inside `showMyCode()` stays for a request that never drew the page |
| 9 | The browser probe ships only where the browser road is the only one — a shell's vault has already answered, on the wire, before the page reaches the device | A case per road, each asserting the row is drawn before asserting what is not in it |
| 10 | The refusal tells a build with no vault from a platform that refused, on the vault itself rather than on `nativephp-internal.running` | The null vault keeps the sentence written for it; a bound vault that answered no gets one naming what the reader can do, in all 26 locales |

#### 8. A code that cannot be answered is still offered, and refuses on press

**E2-R23 surface, both handsets.** The requirement says a device that can
neither accept a pairing frame nor carry a relay *"MUST NOT offer to show a
code, and MUST name the direction that can complete instead."* The second half
is done well. The first is not: **Show my code** renders as an ordinary,
enabled card — `disabled` is false and there is no `aria-disabled` on either
handset — and the refusal only appears after it is pressed. The card is
unchanged afterwards, so the same press can be repeated indefinitely.
`PairingAnswerability::canBeAnswered()` is consulted inside
`PairingFlowModal::showMyCode()`, which is to say after the offer has been
made, while the step that draws the two cards asks nothing. Evidence:
`e2-r23-choose-direction-android.png`, `e2-r23-refusal-{android,ios}.png`.

#### 9. The biometric offer is decided by a browser probe, not by the platform

**E5-R28 surface, iPhone.** The row offering Face ID enrolment is rendered
because the WKWebView exposes `window.PublicKeyCredential`, and
`app-lock-settings-section.blade.php` carries
`x-init="if (window.PublicKeyCredential) { $wire.set('biometricCapable', true) }"`.
The platform's own answer was the opposite, and was on the wire before the
probe ran: the server-rendered snapshot for `/data-devices` fetched from the
device carries `biometricCapable: false`, and
`Library/Application Support/storage/logs/laravel.log` records why, once per
render — `BiometricKeyVault: this device cannot gate an entry behind a
biometric. {"reason":"none_enrolled"}`, i.e. `LAError.biometryNotEnrolled`
from `canEvaluatePolicy(.deviceOwnerAuthenticationWithBiometrics)`.

The A51 is the control: there `window.PublicKeyCredential` is `undefined`, the
probe cannot fire, the snapshot says `biometricCapable: true`, and the row is
correct. So the defect is invisible on exactly the platform whose WebView has
no WebAuthn, and shows on the one that does.

The requirement's other two clauses hold on both handsets. The check does
demand the authentication the entry is written under — `BIOMETRIC_STRONG`
against a Keystore key created with `AUTH_BIOMETRIC_STRONG`, and
`.deviceOwnerAuthenticationWithBiometrics` against a keychain item written
`.biometryCurrentSet` — and the refusal is recorded with the cause the
platform gave. Evidence: `e5-r28-affordance-offered-ios.png`,
`e5-r28-ios-capability-refusal.txt`, `e5-r28-android-capability-answer.txt`.

#### 10. The enrolment refusal blames the build for a device limitation

**E5-R28 surface, iPhone.** Pressing **Enroll** reaches
`ManagesBiometricEnrollment::browserEnrollmentRefusal()`, which sees
`nativephp-internal.running` and answers
`auth::app_lock.error_enroll_unsupported`: *"This version of Beatrax has
nowhere to store an unlock key, so biometric unlock is not offered. Your
device is not the limitation."* Every clause of that is wrong on this device.
The build does carry the vault — `vendor/beatrax/mobile-biometric-vault` and
its `Facades/BiometricVault.php` are both on the phone, and the iOS half of
the plugin compiled into this build. Biometric unlock *is* offered, two rows
below the sentence. And the device is precisely the limitation: the platform
answered `none_enrolled`. The one thing the reader could act on — enrol a face
— is the one thing the copy tells them not to bother with. Evidence:
`e5-r28-enroll-refusal-ios.png`.

## Walked — narrow widths and the locales that expand

Walked on 2026-09-14 against `9c8602497`, on a served instance at 320, 375,
390, 640 and 1440, in English and in Slovenian.

**Why Slovenian.** Not because a long language was wanted in the abstract: the
26 locale trees were compared key by key against English first. All 25
non-English locales carry exactly the same 3450 comparable keys, and Slovenian
expands hardest at the 95th percentile — **1.91x** — with Greek behind it at
1.88x. German, the reflex choice, is 1.67x and would have found less. The
measurement is cheap and repeatable, and it is what picked the locale.

**The sweep, and what it does not cover.** 42 authenticated routes, fetched and
their body swapped into the live document so the media queries evaluate against
the real viewport. An iframe was tried first and does not work — the app's own
CSP blocks framing, so `contentDocument` is null. The swap runs the server's
HTML **without Livewire or Alpine**, so anything laid out by script — chart axis
ticks, finding 5 above — is outside what it measured.

**The detector was positively controlled before any of its zeroes were
believed.** A 580px element planted in a 390px viewport: 0 offenders before, 1
after and it was the planted one, 0 again once removed. Worth doing — the first
detector written here scored 0 on `/inboxes`, the page that prompted it,
because `truncate` sets `overflow: hidden` and the clipping never reaches an
ancestor's `scrollWidth`. A control written from one observation shared its
blind spot; the one that found anything measures the clipped element itself.

Overflow past the viewport: **zero** on all 42 routes, at 390 and at 1440, in
both locales. The defects below are not overflow. They are text that fits
inside a box drawn far too small for it, which is why a sweep looking only for
overflow — including the one recorded in this file's own notification-row
comment, *"measured at 375px and 411px: nothing overflows"* — reported clean.

### What this walk found

Numbered on from the ten above.

**Both are fixed**, in the pull request this row arrives in, and both are one
shape: a column that will not shrink beside the column carrying what the row is
actually about. The rigid column wins, and the identity is what disappears.

| # | Fixed by | Re-measured |
|---|---|---|
| 11 | The inbox row stacks below `sm` and is a row above it, the way the drift row and the system alert already are; its action cluster wraps and is rigid only once there is a row to be rigid in | Address column 72px -> 324px at 390, 0px -> 309px in Slovenian at 375; clipped runs on the page 3 -> 0; the action cluster 17px past its card -> 0. At 1440 the address column is 706px on one line, unchanged |
| 12 | `.flex-1` takes a content basis below `sm` as well as at a coarse pointer | `/notifications` clipped runs 9 -> 0 at 390 with a mouse; no other of the 42 routes moved and nothing new crossed an edge; at 640 `.flex-1` computes back to a `0%` basis |

### 11. Two inboxes both draw as "demo-1+..." with a Disconnect beside each (phone)

**G4-R1 surface. 390x844, English — and it is worse, not better, in the other
25 locales.** The row is `flex ... justify-between` with the address column
`min-w-0 flex-1` and the action column `shrink-0`. The actions take their full
236px of a 358px row and the address takes what is left:

| row | address needs | address gets | actions |
|---|---|---|---|
| `demo-1+gmail@beatrax.local` | 179px | 72px | 236px |
| `demo-1+microsoft@beatrax.local` | 204px | 72px | 236px |
| `mailings@hema.nl` | 110px | 60px | 198px |

72px renders as `demo-1+...` — **the same eight characters for both accounts**,
with a **Disconnect** button beside each and nothing else on the row to tell
them apart. In Slovenian the column is 0px and the address is not drawn at all.

This is not the fine-pointer artefact #12 is. Emulating the phone — both
coarse-pointer rules `app.css` applies, the content basis and the 44px touch
floor — moves the column by 1px, to 71. The 44px floor makes the action column
wider, so a real handset is at least as bad as the measurement.

Mitigated at one step only: `disconnect` carries a `wire:confirm` that names
the email, so the wrong account is named before it goes. The list itself still
does not say which row is which.

### 12. A notification's own text gets 22px of a 232px row (narrow window, mouse)

**G4-R1 surface. 390x844.** Between a `shrink-0` timestamp (88px) and a type
chip (78px), the `min-w-0 flex-1` column holding the title and body computes to
**22px** — 41% of the title visible, 42% of the body. The row is `flex-wrap`
and never wraps: a zero-basis item contributes nothing to the line, so there is
never an overflow for the wrap to resolve.

`app.css` already carries the remedy, written from this exact surface — *"the
body of every alert was a 4px column fifty-two lines deep, between a type chip
and a timestamp that would not shrink"* — and it is inside
`@media (pointer: coarse)`. A phone is fine; the declaration takes the page from
nine clipped runs to zero and back when removed. What is not fine is a **narrow
window with a mouse**, which is reachable: the desktop window opens at 1100
wide, declares no minimum, and `rememberState()` reopens it wherever it was
dragged to.

## Adding a row

Walk it, then add the row in the same pull request as the behaviour. **The
captures themselves do not go in the pull request.** Name them in the evidence
column, dated and per-manifest (`<date>-<what>/`), and keep the files on the
machine that walked them; the names are what lets a later walk of the same
identifier add a row rather than overwrite one.

`.docs/evidence/` is ignored for that reason, and the reason is not the one
this page used to give. It said `.docs/` is excluded from every shipped bundle
(`config/nativephp.php`), so screenshots there never reach a build. That is
true and it is beside the point: **this repository is public.** 63 captures of
a real ledger on a real handset were committed before anyone asked what a
bundle exclusion does not bound, and removing them meant rewriting history
across twelve branches — after which the blobs are still fetchable from GitHub
by hash. A bundle exclusion bounds a build. It does not bound a repository, and
neither does `.gitignore` once a file is already committed.
