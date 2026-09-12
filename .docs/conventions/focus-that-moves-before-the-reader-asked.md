# Focus that moves before the reader asked

`autofocus` moves focus when a document loads. A sighted reader sees a cursor
land in a box and saves a keystroke. A screen-reader reader is put somewhere
they did not ask to be, having heard none of the page that explains it, and a
magnifier reader has the viewport jumped for them. On a phone the soft keyboard
comes up over the content in the same moment.

Focus that moves because the reader **acted** is the opposite of that, and it is
correct: a modal that opens has to take focus, and a field a click revealed has
to be the thing you can type into. The attribute is a bad way to say so, because
it says "on load" and means something else at every site that uses it.

Twelve templates wrote it. They were doing three different things.

## What the attribute actually does

A document collects its autofocus candidates while it parses and flushes them
once. Two facts follow, and both of them decide sites below:

- **After the flush, a later one is ignored.** The insertion steps return early
  when the document's autofocus-processed flag is set, and the flag is set as
  soon as focus leaves the body — which a click does. An element morphed in by
  Livewire after a button press is never a candidate. The attribute on one has
  never fired, in any browser.
- **A dialog and a popover read it themselves.** The dialog focusing steps run
  on `showModal()` and the popover focusing steps run on `showPopover()`, and
  both look for `autofocus` directly rather than through the candidate list. On
  those two elements the attribute is not load-time focus at all — it is the
  platform's documented way to say where an opening surface starts.

So the same word covered focus at load, focus on a reader's own press, and
focus that never happened.

## The rule

**Focus on load is removed. Focus a reader asked for is kept, and written as
focus** — at the moment the element appears, rather than declared on markup that
may or may not be read when that moment comes.

| Site | Was | Is |
|---|---|---|
| Auth — sign-in, sign-up, reset, forced change, add user | load-time focus on the first field | nothing takes focus |
| Mobile — import bootstrap | load-time focus on the username | nothing takes focus |
| Reports — the report-name box | an attribute that never fired | `x-init` when the box is revealed |
| Desktop — the close prompt | dialog focusing steps | the button focuses itself on the event that opens the dialog |
| Import — the rename popover | dialog focusing steps | focus on the event that opens the dialog |
| Open banking — disconnect confirm, warning acknowledgement | dialog focusing steps | `x-init` when the modal is inserted |
| Core — the help tip | popover focusing steps | unchanged |

## The five sign-in screens, decided once

A page that exists to receive one credential is the strongest case there is for
focusing its first field, and it is still focus at load. All five lose it, for
reasons that are about these pages rather than about the rule:

The sign-in screen carries an alert saying a restore happened and naming the
snapshot it wrote; the forced-change screen's subtitle is the only explanation
of why the application will not go on; the sign-up screen puts a language card
and a country card **above** the account form, where a reader is meant to see
them. Focus at load skips past all of it — the reader arrives in a text box with
the page unread behind them. Against that, a sighted keyboard reader on these
screens pays one Tab: nothing focusable sits between the top of the document and
the first field, because these screens draw no menu bar. One keystroke against
the page is not a trade worth making, and making it differently on one of the
five would be worse than either answer.

## Writing the focus

Two spellings, and which one is right depends on when the element arrives.

**The element is inserted by the action.** A field behind an `@if`, a modal
behind a flag — Alpine initialises it at the moment it appears, so `x-init` is
that moment:

```blade
<input x-data x-init="$nextTick(() => $el.focus())" />
```

`$nextTick` is not decoration: the element is being inserted as the expression
runs, and focusing an element the browser has not laid out yet does nothing.

**The element is in the document from the first paint.** A Flux modal that is
always rendered and opened by an event has no moment of its own, and `x-init`
would fire at load — on an element inside a closed `<dialog>`, where `focus()`
is a silent no-op. Take the event that opens it, on the element that takes the
focus, so `$el` is the answer and nothing has to be named twice:

```blade
<button
    x-data
    x-on:modal-show.document="$event.detail && $event.detail.name === @js($modalName)
        && $nextTick(() => $el.focus())"
>
```

The name match is deterministic rather than racy because the component sets its
state and dispatches `modal-show` on one round-trip, and Flux's own dialog
answers the same event by calling `showModal()`. Its listener is registered
first — Alpine initialises the `<dialog>` before anything inside it — so the
dialog is open by the time this one runs.

Bind it on an ancestor and reach down with `x-ref` where the element that
answers the event is not the element that takes focus. The rename popover does
this: the listener sits on its `<form>`, which is what owns the modal's identity
check, and names `$refs.friendly` for the control inside.

Component tags are **not** a reason to reach down. A component built on
`$attributes` forwards whatever it is not consuming as a prop, `x-on:` included:
`x-core::form-field` splits them into `$bindings` (`wire:`) and a `$passthrough`
of everything else, and renders both onto the control; `x-core::secondary-button`
and its siblings `merge()` onto the `<button>`. `goals-page.blade.php:66` passes
`x-on:click` through `x-core::neutral-button` and it lands. So `x-on:` on a
component tag reaches the control, and `$el` inside it is the control.

The trap on the way back out: **Flux's `<dialog>` binds this same event itself**,
to open the modal. A test — or a reader — that walks up for the nearest element
carrying `x-on:modal-show.document` gets Flux's `handleShow($event)` and not
this. Read it off the element that was written, not off the nearest one that
matches.

## The one that stays

`x-core::help-tip` keeps `autofocus`, on the panel rather than on a control
inside it, and it is the only one left in the product.

Its panel is a native `[popover]` driven by `popovertarget`, with **no
JavaScript in the path at all** — which is the whole point of the component,
because `title` is inert on both shipped phones and a tip that needs hover is a
tip neither of them has. A closed popover is not focusable, so the load-time
pass skips it; the attribute is read a second time by the popover focusing steps
when the reader presses the mark. It is a reader's own press, and the mechanism
the platform documents for it.

Converting it would mean adding script to the one component that has none, to
reproduce behaviour the platform already gives correctly. That trade is worse
than the finding it clears, so the site keeps the attribute and the guard pins
it by name. [Help a reader can open](help-a-reader-can-open.md) holds the rest
of that component.

## The guard

[`FocusMovesOnlyWhereTheReaderAskedItToArchTest`](../../tests/Contracts/FocusMovesOnlyWhereTheReaderAskedItToArchTest.php)
reads every Blade view and fails on the attribute, with the help tip as its one
pinned entry. The pin is re-checked from both directions: the template has to
still carry the attribute, and it has to still be a popover, so an exemption
cannot outlive the reason it was granted.

It is a local stand-in for a hosted accessibility rule, and it exists because
that rule scores **new code only** — it reports a site on the day a branch
happens to edit that line, and says nothing about the other eleven. Two of the
twelve had been reported that way, years of templates apart, and fixing either
one alone would have left the shape.

Two places it is knowingly wider than the hosted rule, stated here rather than
discovered later. It reads the attribute on a component tag as well as on a
native element, because `x-core::form-field` forwards it to the control and the
rendered page is identical either way. And it reads every Blade view, including
the handful under `resources/`, where the hosted analysis reads only the
module tree — this rule is about what a reader experiences rather than about a
code smell, and the tree a template sits in does not change that. Both
directions are clean today.

The four PHP code-smell rules guarded the same way are
[Analyser rules enforced locally](analyser-rules-enforced-locally.md); they read
a narrower scope, for reasons that page gives.

## Related

- [An icon-only action says its verb on touch](an-icon-only-action-says-its-verb-on-touch.md)
  — the other place a control had to say something to a reader it was not saying
- [Help a reader can open](help-a-reader-can-open.md) — the popover this page exempts
- [Writing an arch invariant](arch-invariants.md) — the mechanics the guard follows
- [Analyser rules enforced locally](analyser-rules-enforced-locally.md) — the sibling guards
- [G3 Accessibility](https://github.com/beatrax-app/spec/blob/main/10-functional/features/g-ux/g3-accessibility.md)
  — the conformance target these decisions are taken against
- [60-brand/accessibility.md](https://github.com/beatrax-app/spec/blob/main/60-brand/accessibility.md)
  — focus is always visible, in both themes
