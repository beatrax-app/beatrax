# A mark that is a picture carries U+FE0F

Some of the marks this app draws are ordinary text characters and some are
pictures. Unicode does not always let the character alone say which, and the two
shipped phone engines answer the ambiguous cases differently. The rule here is
that **any mark meant to render as a picture is written with the emoji
presentation selector, `U+FE0F`, appended** — even where one engine happens to
draw the picture without it.

## What goes wrong without it

A code point can be *emoji-presentation by default* (🗑 is not; ✅ is) or *text
presentation by default*. For a text-default code point a browser is supposed to
pick a monochrome glyph, and only pick the colour emoji if `U+FE0F` follows.

What each engine actually does depends on what fonts it has:

| Mark | Written | iOS / WebKit | Android WebView |
|---|---|---|---|
| Gear | `⚙` (U+2699) | colour emoji | monochrome glyph |
| Envelope | `✉` (U+2709) | colour emoji | monochrome glyph |
| Telephone | `☎` (U+260E) | colour emoji | monochrome glyph |
| Gear | `⚙️` (U+2699 U+FE0F) | colour emoji | colour emoji |

WebKit's UI font stack has no monochrome glyph for any of the three, so Core Text
falls through to Apple Color Emoji and paints the picture even though nothing
asked for one. Chromium on Android finds all three in Noto Sans Symbols 2 and
paints the line art the character actually asked for. The two phones then draw
the same screen differently, and the difference reads on the Android phone as
"the emoji did not render".

Measured on an iPhone 12 mini and a Galaxy S24 Ultra, both running the same
build: the sidebar's *Settings* and *Email* rows, the counterparty support
block's mail and phone marks, and the notification *Receipt* chip.

## The rule

Append `U+FE0F` at the site. It is invisible in an editor, which is why this page
exists and why the sites that carry one say so in a comment:

```php
['destination' => Destination::Settings, 'icon' => '⚙️', ...]
```

`x-core::emoji-action` marks (🗑️ ✏️ 🏷️ ✖️ 🗄️) and the onboarding marks (✉️ ⬇️)
already do this; the sites listed above were the ones that had been written bare.

## Where the rule does not reach

**A glyph that belongs to a set stays with its set.** The command palette's
keycap chips are `↑ ↓ ↩ esc` in a row; `↩` (U+21A9) diverges the same way, but a
colour emoji inside one keycap and line art inside the other three is worse than
either engine's answer. Both of its call sites are inside `hidden-touch
max-lg:hidden`, so no phone renders one, and the desktop draws all four the same
way. Left bare deliberately.

This is the same exemption the mark policy already grants the mobile top bar's
`☰` / `⌕` pair: a control that belongs to its neighbours more than to the rule
follows its neighbours.

## The tension this leaves

The sidebar icon set is otherwise monochrome geometry — `◆ ≡ ↗ ▦ ◈ ↻ ▷ ⇉ ◬ ⚠ ⊙
⊞ ◎ ◫ ▤ ✓ ⊕ € ◉ ❋ ⌕ ◇ ⇄` — and `⚙️` and `✉️` are now the two colour pictures in
it on **both** platforms, where before they were two colour pictures on iOS only.
That is what "the phones must match, and the iPhone is the reference" resolves
to. Making the set uniformly monochrome instead is a one-character change per
site — `U+FE0E`, the text presentation selector, in place of `U+FE0F` — and it
would change what the iPhone draws today.
