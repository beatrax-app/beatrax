# An overlay answers the Escape the reader pressed

Eight surfaces in this tree opened over the page and could not be closed from a
keyboard. Two of them said in their own comments that they could.

The mechanism is the same at all eight, and it is not "somebody forgot Escape".
Six of them bound no key at all; the other two bound `@keydown.escape` on the
panel — which is a listener that only ever hears what is typed **inside the
panel**, on a panel nothing ever types inside.

## Why a panel-bound key never fires

A keyboard event is dispatched at `document.activeElement` and bubbles up that
element's ancestors. A listener on an element hears a key only when focus is on
it or inside it.

None of these surfaces moves focus into itself when it opens. That is
deliberate and it is written down: `x-trap.inert` on the drawer crashed the
Android WebView renderer outright — the tap registered, the renderer died, and
every later interaction did nothing while the page still looked normal — so the
trap was removed from both the drawer and the bottom sheet. Removing it removed
two things, and only one of them was noticed. A trap confines focus; it also
**moves focus in**, and the panel-bound Escape had been living off the second.

So after the removal the reader stands where they already were:

| Surface | Where focus is when it opens | Where Escape was bound |
|---|---|---|
| Phone nav drawer | the hamburger, in the top bar the layout draws **after** the drawer | the drawer panel |
| Bottom sheet | the trigger that dispatched `open-sheet`, in the page behind | the sheet panel |

In both, the listener is on a **sibling** of the element the key is typed on.
The event never passes it. Escape did nothing, on every one of the four bottom
sheets the pots page draws and on the only navigation a phone has.

## The trigger is not a safe place for it either

The anomaly snooze menu bound `x-on:keydown.escape` on the button that opens the
menu. That works for exactly as long as the reader does not move — and the only
way to choose a snooze window with a keyboard is to Tab into the menu, at which
point focus is inside the panel and the trigger stops hearing anything. Escape
worked until the moment it was needed.

## The rule

**Bind the dismiss key at the window, on the element that owns the open state,
gated on that state.**

```blade
<div x-data="{ open: false }" x-on:keydown.escape.window="open = false">
    <button x-on:click="open = ! open">…</button>
    <div x-show="open" x-cloak x-on:click.outside="open = false" role="menu">…</div>
</div>
```

The wrapper, not the button and not the panel: it is the one element that
contains both, so it is the one whose state the key should be talking about.
Ten surfaces in this tree were already written this way — the ledger and report
filter chips, the date and time pickers, the calendar account popover — and the
eight that were not now match them.

`.window` is what makes the listener reachable; the gate is what keeps it
honest. A page draws several of these, every one of them hears every Escape,
and a surface that is already shut must not act on a key aimed at something
else.

## Where two surfaces are open at once, the nearer one owns the key

The command palette opens from the search row **inside** the drawer, and covers
it. An Escape there belongs to the palette. So the drawer declines the key while
the palette is up rather than closing both:

```blade
@keydown.escape.window="$store.mobileNav.drawerOpen && ! $store.overlay.has('palette') && $store.mobileNav.close()"
```

`$store.overlay` already exists for this — the layout reads it to mark `<main>`
inert while any overlay covers the page — so the ordering is answered from the
state that was already tracking it rather than from listener registration order,
which nothing controls.

The palette settles the same question one level down, and it is the reason it is
the single pinned exemption to the guard below. Two of its own surfaces stack:
the token-autocomplete overlay sits over the palette, so its Escape dismisses
the overlay first and only closes the palette when no overlay was open. That is
an *order*, and an attribute modifier has no way to spell one — the decision
lives in `onKey()` in `resources/js/palette.js`. The pin is re-checked from both
directions: the template has to still delegate to that handler, and the handler
has to still branch on the key.

The palette is also the example worth copying if a surface ever does need focus
moved into it. It records the element that opened it, focuses its input,
confines Tab to its own rows, and puts focus back on the opener when it closes —
all in script, without `x-trap`, and therefore without the renderer crash that
took the trap away.

## What this does not fix

Focus is still not moved into the drawer or the sheet, and still not confined to
them. A reader can Tab out of an open overlay into whatever the layout leaves
reachable. `<main>` is `inert` while either is up, which covers most of the page
but not the top bar — deliberately, because the scrim is `aria-hidden` and the
hamburger is a screen reader's only way back out of the drawer.

The key being reachable is the part that is fixed here. Restoring the trap needs
one that does not walk the document marking siblings inert, and that is a
separate piece of work from the one this page describes.

## The guard

[`AnOverlayAnswersTheEscapeTheReaderPressedArchTest`](../../tests/Contracts/AnOverlayAnswersTheEscapeTheReaderPressedArchTest.php)
holds the invariant `everyOverlayAnswersEscapeAtTheWindow`. It reads every Blade
view with the markup lexer rather than a pattern — an Alpine expression puts a
`>` inside an attribute and `[^>]*` ends the tag on it — and calls an element an
overlay surface when it is shown conditionally (`x-show`, or inside a
`<template x-if>`) **and** either takes a `role` naming a dialog, menu or
listbox, or dismisses itself on an outside click.

The role is read in its bound spelling too: the drawer is a dialog only below
the width at which the stylesheet turns it into the static sidebar, so it
carries `:role` rather than `role`. An element marked `aria-hidden="true"` is
not a surface — that is the scrim, and the reader is never standing in it.

A surface is answered when it, or an ancestor of it in the same template, binds
a `keydown`/`keyup` listener carrying both `.window` (or `.document`) and an
Escape. A key bound without that modifier does not count, which is the whole
finding: the guard would have stayed green on the two sites that had one.

It reports **8** unpinned sites on the commit before this one and 0 after,
across 25 surfaces in 283 templates. Both denominators are asserted before any
verdict is read, because a walk that stopped reading reports the same clean tree
a walk that found nothing does.

## Related

- [Focus that moves before the reader asked](focus-that-moves-before-the-reader-asked.md)
  — the other half of the same subject: where focus is allowed to go on its own,
  and the two spellings of focus a reader asked for
- [Help a reader can open](help-a-reader-can-open.md) — the one surface that gets
  Escape, light dismiss and focus return from the platform with no script at all
- [Writing an arch invariant](arch-invariants.md) — the mechanics the guard
  follows, including what a pin has to prove to keep its exemption
- [G3 Accessibility](https://github.com/beatrax-app/spec/blob/main/10-functional/features/g-ux/g3-accessibility.md)
  — the conformance target these decisions are taken against
- [G6 Keyboard](https://github.com/beatrax-app/spec/blob/main/10-functional/features/g-ux/g6-keyboard.md)
  — the surfaces that are required to be operable end to end from the keyboard
