# Help a reader can open

Some screens name a thing the reader has no way to learn from the screen. *Ready
to assign* is an arithmetic result, *If overspent* offers two options that differ
only in who absorbs a shortfall, and *Chains* is the name of a data structure.
The page shows the answer and never the question.

`x-core::help-tip` is the affordance that answers it: a small mark beside the
label, and a panel that opens under a tap or a keypress.

## Why not a tooltip

`title` fires on hover and a phone has no hover — the same finding that put a
press-and-hold tooltip on every icon-only action
([an icon-only action says its verb on touch](an-icon-only-action-says-its-verb-on-touch.md)).
That page's answer does not transfer here: it reveals one word for as long as
the mark is held, and this is three sentences. It has to open, and stay open.

| Considered | Why not |
|---|---|
| `title` | Inert on both shipped phones. It is the affordance being replaced. |
| A `<details>` disclosure | Works everywhere, but it expands *in flow*: inside the budgets grid it is a `<th>` inside an `overflow-x: auto` scroll container, and the panel would push the table's own layout around while it is open. |
| An Alpine panel | A third popover mechanism beside the FX disclosure and the lock veil, both of which already use the platform's. `x-trap.inert` on an overlay has also crashed the Android WebView renderer outright once. |

## What it is

A `<button popovertarget>` and a `<div popover>`, and nothing else — no
JavaScript in the path. Escape, light dismiss and focus return come from the
platform; the Close button inside is for the finger that does not know light
dismiss exists.

**The mark is a glyph, not an emoji.** A sole icon *action* is an emoji here
because the picture stands in for the verb. Reading is not a verb, so the mark
is a plain ASCII question mark inside a drawn circle, the same shape `.fx-icon`
uses. Drawn rather than typed for the reason
[a mark that is a picture carries U+FE0F](emoji-presentation-selector.md)
records: `ⓘ` and `ℹ` are exactly the ambiguous kind, and WebKit paints a colour
picture where Chromium paints line art.

**Anchor positioning is deliberately absent.** Without it the UA centres a
popover, which is what a 375px screen wants anyway, and the anchor properties
would be stripped from the compiled stylesheet by the same Lightning CSS pass
that strips them from `.fx-popover`. One centred panel reads the same on the
phone and on the desktop.

## Where the mark sits

**The mark takes the type of the label it explains.** `vertical-align: middle`
centres it on the x-height, which is as far as that keyword reaches, and
`translateY(calc((1ex - 1cap) / 2))` carries it up to the middle of the cap
height. Both metrics come from the mark's own inherited font, so the label and
the mark have to resolve to one font-size or the lift aims at the wrong text.
The size therefore sits on a block holding both, and the label restates nothing:

```blade
<x-core::page-heading>
    {{ Lang::get('ledger::reconcile.heading') }}
    <x-slot:tip>
        <x-core::help-tip topic="reconcile" … />
    </x-slot:tip>
</x-core::page-heading>
```

`x-core::page-heading` renders that block for a page title; a `<th>` and the
budgets ready-to-assign label already are one. The mark stays outside the `<h1>`
— a button in there is read out as part of the heading, and the panel is a
`<div>`, which an `<h1>` may not contain.

**A help mark is never a flex item.** A flex item inherits the ROW's type rather
than the label's, and `vertical-align` is ignored on one outright, so no
font-relative offset can reach the label's cap height from inside a row. An arch
test reads the enclosing element of every mark in the tree.

**The space before the mark is non-breaking.** It is the gap, and gluing it to
the last word is what stops a heading with no room left from dropping the mark
alone onto a line of its own.

## The rule

**The panel's heading is the label of the thing it explains, passed in.** Not a
second string that says roughly the same — the same string, so the two cannot
drift apart:

```blade
<x-core::help-tip
    topic="budgets-ready"
    :label="Lang::get('budgets::messages.ready.label')"
    :body="Lang::get('budgets::help.ready_to_assign')"
/>
```

**Where the body describes a control, it names that control with the control's
own key.** The overspend body carries `:reduce` and `:carry`, filled from the
two `<option>` labels; the reconcile body carries `:complete`, filled from the
button's label; the recurring body carries `:setting`, filled from the label the
settings page draws. A translator then cannot describe an option in words the
option does not use, and a test checks it in all 26 locales.

## Where the copy lives, and how it stays honest

One file per module, `Modules/<X>/Resources/lang/<locale>/help.php`, so
`Modules/*/Resources/lang/en/help.php` is the whole corpus in one glob.

Every key carries a tag-only docblock naming the `.docs` page it was written
from:

```php
    /** @link ../../../../../.docs/features/budgets/architecture.md#the-genesis-to-target-fold-carryoverquery */
    'ready_to_assign' => 'Money that has arrived and has no envelope yet: …',
```

That link is load-bearing rather than decorative. A lang file is a `.php` file
under `Modules/`, so `M6` resolves it like any other — **and resolves the
`#fragment` too**, which means renaming the section the copy was written from
fails the build rather than leaving a sentence pointing at a page that no longer
explains it.

The `.docs` page is not the copy. Those pages are written for whoever maintains
the code — they say which listener was added and what broke without it. The help
answers what the feature is for and how to use it, and where a page has nothing
a reader would want, the right answer is no tip rather than a padded one.

## Where a tip does not go

- **On a control that is not on the screen.** The overspend tip is on the
  desktop grid's column header because the overspend select is only drawn there.
  A tip beside a control a phone reader does not have is worse than none.
- **Once per row.** The panel needs an id, and an id has to be unique. A tip
  belongs on the column header or the section heading, not on each row under it.
- **On anything the screen already says.** `/reconcile` opens with a sentence of
  lede and `/chains` with a subtitle; the tip answers what those two sentences
  assume the reader already knows, and does not repeat them.

## The measured numbers

- **`min(24rem, calc(100vw - 2rem))`.** 24rem is 384px, which is wider than an
  iPhone 12 mini's 375, so the viewport arm is the one that applies there and
  the panel lands at 343px. On a Galaxy S24's 411px it lands at 384.
- **The 44px reach is a halo, not a floor.** The trigger is an 18px circle
  beside a heading, and `min-width: 44px` replaces `min-width: auto` — a floor
  on it would squeeze the heading beside it. `.help-tip` therefore takes the
  shared `::after` band in `resources/css/app.css` and opts out of the floor,
  the same way `.fx-disclosure-trigger` does.
- **5.98px, and now 0.01.** Measured headless against the built stylesheet at
  375px and 411px, in five locales and at three root sizes: the mark's centre
  sat 5.98px below the cap-height centre of a 28px page heading, 0.99px below a
  12px label's, and 1.12px below a column header's — one offset could not have
  fixed all three, which is why the offset is written in the label's own units.
  Every one of them now reads 0.00 or 0.01, the mark stays 18×18 and its halo
  44×44, and an engine with no `cap` unit keeps `middle` at 2.7px.
- **14.56px of circle.** A mark laid out as a flex item has no basis to hold it
  open: on the Latvian recurring title at 375px the heading took the width it
  needed and left the circle an oval. In its label's inline flow it cannot be
  squeezed at all.

## Related

- [An icon-only action says its verb on touch](an-icon-only-action-says-its-verb-on-touch.md)
  — the sibling finding, and why its answer is a held tooltip rather than a panel
- [A mark that is a picture carries U+FE0F](emoji-presentation-selector.md) —
  which characters the two phone engines disagree about
- [A translated line has a call site](a-translated-line-has-a-call-site.md) —
  what a new `help.php` key owes the 26 locale files
- [Conventions](00-index.md) — the comment policy these files are shaped by
