{{--
    The magnifier, drawn rather than typed.

    ⌕ (U+2315) has no glyph in Inter, so WebKit falls back to whatever the
    system offers and draws it small and low: measured 10x17 with about 9x9 of
    ink on an iPhone 12 mini, beside 15px placeholder text. The top bar had
    already replaced it with this path for the same reason; the transactions
    search field still typed the character, so one screen carried both.

    The sites that keep the character are the ones where it sits in a column of
    other characters — the sidebar's .ic slot, the command palette's row icons,
    the /counterparties and /reports view switchers. A drawn icon there would
    be the only drawn thing in a row of glyphs, which is the defect the other
    way round.
--}}
<svg
    {{ $attributes->merge(['class' => 'h-5 w-5']) }}
    fill="none"
    viewBox="0 0 24 24"
    stroke="currentColor"
    stroke-width="2"
    aria-hidden="true"
>
    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z" />
</svg>
