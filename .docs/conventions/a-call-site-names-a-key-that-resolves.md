# A call site names a key that resolves

`Lang::get()` returns the key when no file declares it. So a mistyped or
never-written key does not throw, does not log, and does not render blank — it
renders `core::components.toast_undo` onto the button, in all twenty-six
languages at once.

Nothing saw it. `TranslationParityArchTest` measures the locales against each
other, and a key missing from **all** twenty-six is in parity by construction.
[A translated line has a call site](a-translated-line-has-a-call-site.md) asks
the opposite question — whether anything renders a *declared* line — and a key
that was never declared has nothing for it to start from. The two guards met
in the middle and left this gap between them.

`tests/Contracts/EveryKeyACallSiteNamesResolvesToALineArchTest.php` is what
fails now. The two of them together are what "the copy is complete" means: one
proves every line is reachable, the other proves every reference lands.

## The eight keys it caught

Referenced by shipped code, declared by no locale:

| Key | Where the reader met it |
|---|---|
| `core::components.toast_undo` | The label of every Undo button. The toast host is mounted in the layout, so the raw key was in the rendered HTML of all 21 authenticated routes. |
| `core::help.tip.about` | The help tip's `aria-label` — a screen reader announced "core colon colon help dot tip dot about". |
| `core::help.tip.close` | The tip panel's Close button. |
| `core::components.install.dismiss_caption` | The word the dashboard install hint's ✖️ shows when it is held. |
| `core::errors.no_longer_here` | The message for a row another tab already actioned, in EmailScan and Chains. |
| `core::settings.period.move_confirm` · `move_cancel` · `move_apply` | All three strings of the confirm strip that gates the period-day move. |

Two of these were found the same week by different means — one on a phone, one
by a second pair of hands hitting `core::demo.envelope_move_*` rendering as a
budget memo. It keeps recurring because writing the call site and writing the
line are two separate acts and only one of them was checked.

## What counts as a call site

A **translation call** with a **literal first argument**. Nothing else — the
guard reads the callee, not the string:

```php
Lang::get('core::settings.save')        // checked
view('onboarding::livewire.setup-wizard')  // a template, not a key
```

The two have the same shape and a scan that matched on shape alone would report
every namespaced view name in the product.

## The four traps, and how each is detected

A guard that cries wolf is a guard the next reader learns to skip, so precision
came before strictness. Each of these was hit while writing it.

| Trap | Why it looks broken | How it is told apart |
|---|---|---|
| A runtime-built prefix — `'core::settings.appearance.theme_'.$value` | The literal is a prefix; no leaf is spelled anywhere | The punctuation **after** the closing quote. A `.` means concatenation and a `{` or `$` inside a double-quoted literal means interpolation. Never the key's own last character: a prefix may end in a letter, and the trailing `_` is not what makes that one. |
| A docblock example — `Lang::get('ns::group.key')` in `Modules/Core/Public/Support/Lang.php`'s own header | It is a syntactically perfect call | Comments are dropped before the scan. `token_get_all` removes them from PHP; a `{{-- --}}` block is inline HTML to that tokeniser, so Blade goes through `Blade::compileString()` first — the compiler is the one thing that already knows where a Blade comment ends. |
| A view name | Identical to a namespaced key | Only translation callees are matched. |
| A lang file, and a test | A lang file spells every key it declares; a test may name a missing key to assert what happens | Both directory trees are skipped, the same two the reverse guard skips. |

Seven live prefixes exist today besides the theme one — `NotificationTab`,
`ReviewTab`, `FixedPaymentsFilter`, `SeriesCadence`, `ReportGroupHeading`,
`ReportBuilder` and the country list. Reporting any of them would have been
worse than not running: they are the live copy of six screens.

## The second rule, for the prefixes

A prefix cannot be checked leaf by leaf — the leaf is chosen at runtime. What
can be checked is that the subtree exists at all, so the guard asks the
translator for the group whole and requires at least one line to sit under the
prefix. An empty subtree means every arm of that call renders its own key.

## What it still cannot see

A suffix that arrives as **data** rather than as an expression. The demo
seeder builds `Lang::get('core::demo.'.$move['memoKey'])` and the leaf lives in
an array literal further up the file; the prefix rule proves `core::demo.` holds
lines, and nothing proves it holds *that* one. Naming the suffix a key would
mean guessing which of a file's string literals are key fragments, which is the
guessing that makes a guard cry wolf.

## Adding the line, once the guard names the key

The key goes into `Modules/<X>/Resources/lang/en/` **and all 26 counterparts** —
parity fails on anything less, and one locale short is a reader seeing English.
Reuse the word the locale has already settled rather than writing a new one:
`Undo`, `Close`, `Cancel` and `Apply` each existed in twenty-six languages
before these keys did, in `budgets::messages`, `goals::messages` and
`core::settings.language`.

Where the frame around a placeholder would govern a case the label cannot
supply, the frame is what changes — the same answer
[translations awaiting a native reader](translations-awaiting-a-native-reader.md)
records for a noun whose case a different key governs. `core::help.tip.about`
is `About :subject` in English and in twenty-three locales; Estonian, Finnish
and Hungarian take a colon frame instead, because their "about" is a suffix and
a label cannot carry one.

## Related

- [A translated line has a call site](a-translated-line-has-a-call-site.md) —
  the other direction, and the four ways a key is assembled
- [Copy that carries a count](counted-nouns-in-copy.md) — `Lang::get` against
  `Lang::choice`, and why the numeral lives inside the line
- [An icon-only action says its verb on touch](an-icon-only-action-says-its-verb-on-touch.md)
  — what a new `*_caption` key owes its label in all 26 locales
- [Conventions](00-index.md) — the comment policy these files are shaped by
