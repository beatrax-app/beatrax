# An icon-only action says its verb on touch

`x-core::emoji-action` has always carried `aria-label` and `title`, so a screen
reader announces it and a desktop pointer gets a tooltip. Neither reaches a
finger. `title` fires on hover, a phone has no hover, and the result is that
every icon-only action in the product was an unlabelled picture on the two
devices the app is mostly read on.

It is worst where the marks sit together. The goals row offers ✏️ ✅ 🗄️ and the
pots row offers 💰 🔄 🏧 ✏️ 🗄️ — three and five pictures with no word between
them, so the only way to learn which one archives a goal was to press it.

The fix is a **press-and-hold tooltip**: the same word the label already
carries, shown while the mark is held, at a coarse pointer only. Desktop is
untouched and keeps `title`.

## Why holding, and not the alternatives

A caption standing permanently under each mark was tried first and taken back
off. It labelled everything, but it put a word under every icon on every screen
and widened the row to fit it — read on the phone, the product had turned into
a page of captioned buttons.

| Considered | Why not |
|---|---|
| A permanent caption under the mark | What shipped first, and what came back off. It answers a question the reader is not asking on most rows, and it makes the button as wide as its longest translation. |
| A first-run coach mark | Teaches once and is gone. It cannot label ten different marks across ten screens in one overlay, and it does nothing for the reader who comes back in a month. |
| The word beside the mark | 5 pots actions × (44px mark + a word) does not fit 309px of row at 375px. |

The objection to holding is that a gesture nobody is told about teaches nobody.
That is real, and it is weaker here than it looks: **long-pressing an icon-only
toolbar button to see its label is Android's own convention**, so on the
platform this mostly ships to, the gesture is the one the reader already has.

## The rule

**The tooltip's word defaults to the label.** Where the label is a phrase, the
site passes a shorter `:caption`, and that caption is **a word taken out of the
label** — never a second wording of it.

```blade
<x-core::emoji-action
    :label="Lang::get('goals::messages.actions.mark_complete')"
    :caption="Lang::get('goals::messages.actions.mark_complete_caption')"
    wire:click="markComplete({{ $row->id }})"
>✅</x-core::emoji-action>
```

Taken out of the label rather than written fresh, because of WCAG 2.5.3: a voice
control user says the word they can see, and the button only answers to its
accessible name. If the tooltip reads *Complete* and the name says *Mark as
complete*, "tap Complete" lands. An unrelated synonym would not.

`tests/Contracts/AnIconOnlyActionSaysItsVerbOnTouchArchTest.php` holds that the
caption is a case-insensitive substring of the label **in all 26 locales**, and
that it is at most 16 characters. The repo's general
`VisibleLabelInAccessibleNameArchTest` cannot see any of it — both strings
arrive through `Lang::get`, which that guard skips as interpolated.

## A hold must not fire the action

These are real mutations: archive, withdraw, tag. Holding one to read its name
and having it act would be worse than leaving it unlabelled.

A long press ends in a click like any other press, so the click has to be
swallowed — and **where the listener sits is the whole of it.** The handlers are
on a `display: contents` wrapper, not on the button:

> At the target itself, capture and bubble listeners run in **registration
> order**, and Livewire's `wire:click` registers first. Only a capture listener
> on an **ancestor** is ordered ahead of the target's own, and
> `stopImmediatePropagation()` there means the click never reaches the button.

The second half is the event order, which is not the one you would guess.
Measured in Chromium with touch emulation:

```
pointerdown → pointerup → pointerout → pointerleave → click
```

`pointerleave` arrives **after** `pointerup` and **before** `click`. The first
build wired `pointerleave` to the full reset, so it disarmed the guard in
exactly that gap and every hold archived the row it was only naming. It is gone;
`pointercancel` is the only cancellation that is guaranteed to precede no click,
and that is the one that clears the flag.

Chromium raises `pointercancel` the moment a press turns into a scroll, which is
what cancels a hold that was really the start of a drag. The distance check on
`pointermove` stays as the belt for anything that does not.

### And the OS raises a third event, mid-hold

`pointerleave` was not the last handler to tear the tip down. On device, Android
fires **`contextmenu`** about 130ms *after* the tip appears — while the finger is
still down. Measured on a Galaxy S23 over CDP, with real `adb input swipe`
presses rather than emulated events:

```
pointerdown@154   finger down
shown=true@624    tip appears, 470ms in
CONTEXTMENU@748   Android raises its own callout
shown=false@754   x-on:contextmenu.prevent="reset()" blanks the tip
pointerup@1059    finger lifts 305ms later, onto nothing
```

**The tip existed for 130 milliseconds.** Long enough to see something flicker,
never long enough to read it — reported from the field as "it fired, it just
went away directly".

`reset()` was the wrong body for that handler twice over. It hid the tip the
hold had just produced, and it cleared `swallow`, so the guard was disarmed
before the click it existed for — the same defect `pointerleave` caused, through
a different event. `contextmenu` now **only** suppresses the OS callout, and
only while a touch hold is live, so a desktop right-click keeps the browser menu
it never should have lost.

### The tip outlives the finger

A tip that dies on `pointerup` is one the reader never gets a clean look at,
because until they lift, their own thumb is over it. It now stays up for
`LINGER_MS` after release. `stop()` split into `disarm()` (the hold) and the
fade, so a release can end the press without ending the tip; `press()` clears a
pending fade so a leftover from the previous hold cannot blank the current one.

`Modules/Core/tests/Feature/AHeldIconKeepsItsWordOnScreenUntilTheFingerHasLeftTest.php`
drives the state machine over a fake clock at these timings. Seven of its
fifteen checks fail against the pre-fix component.

## The numbers, and where they came from

Measured in Chromium against the real stylesheet and the shipped Alpine
component, with `pointer: coarse` emulated and touch events dispatched over CDP,
at 375px (iPhone 12 mini) and 411px (Galaxy S24).

| | |
|---|---|
| Tip box | 34.8px tall; 49.3px (*Tag*) to 141.1px for the widest caption in the product, Ukrainian *Перейменувати* |
| At 375px | `60vw` allows 225px and `place()` bounds x to `[8, 226]`, so the widest tip fits with room to spare |
| Short tap | Fires the action, shows no tip |
| Hold, then release | Shows the tip, fires **nothing** |
| Hold, then drag | Cancels the tip, fires nothing |
| **16 characters** | Keep captions to about this. Roughly 10.9px per character at `--text-base` |

The tip **teleports to `<body>`** and is `position: fixed`. Both matter: the pots
list is `overflow: hidden`, and the calendar day panel is transformed *and*
scroll-clipped, so a tip rendered in place is cut off by either. Measured, it
clears the pots list's own top edge by 30.5px and stays in the viewport.

The tip is `--text-base`, and it clears the mark by `LIFT_PX` (22px) rather
than the 8px screen-edge inset it used to reuse: on a phone the thumb that
summons the tip also covers the gap immediately above the mark, so the old
clearance put the answer under the finger that asked for it.

The 44px reach is untouched — the wrapper is `display: contents`, so the button
is still the flex item its row lays out and still takes the coarse-pointer
floor.

## Two things the mark gives up on touch

`-webkit-touch-callout: none` and `user-select: none`, both under
`(pointer: coarse)` only. iOS answers the same press with its selection callout
and magnifier, and they are refused separately.

## Where the tooltip does not go

A mouse. `press()` returns immediately on `pointerType === 'mouse'`, so a
desktop keeps `title` and its click is never touched.

## Related

- [A mark that is a picture carries U+FE0F](emoji-presentation-selector.md) —
  which characters need the presentation selector, and why the marks are emoji
  rather than glyphs in the first place
- [A translated line has a call site](a-translated-line-has-a-call-site.md) —
  what a `*_caption` key owes the 26 locale files
- [Which actions ask before they act](which-actions-ask-before-they-act.md) —
  the confirmations behind the actions these marks trigger
- [Conventions](00-index.md) — the comment policy these files are shaped by
