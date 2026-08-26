@props([
    'size' => 'lg',   // 'lg' (h-5 w-5) beside a search field | 'md' (h-4 w-4) in a slot shared with x-core::spinner
])

{{--
    The magnifier, drawn rather than typed.

    ⌕ (U+2315) has no glyph in Inter, so WebKit falls back to whatever the
    system offers and draws it small and low: measured 10x17 with about 9x9 of
    ink on an iPhone 12 mini, beside 15px placeholder text. The top bar had
    already replaced it with this path for the same reason; the transactions
    search field still typed the character, so one screen carried both.

    The sites that keep the character are the ones where it sits in a column of
    other characters — the nav rows' .ic slot, the command palette's row icons,
    the /counterparties and /reports view switchers. A drawn icon there would
    be the only drawn thing in a row of glyphs, which is the defect the other
    way round. A SEARCH BOX is not such a column: the mark stands alone ahead
    of a placeholder, which is the case this component exists for.

    Size is a prop rather than a class because $attributes->merge appends, and
    Tailwind emits h-4 before h-5 — so a caller passing h-4 is silently
    overruled by the default. Measured on the device: both orders render
    21.25px. 'md' is the same box x-core::spinner calls 'md', because in the
    palette the two swap in and out of one 16px slot.
--}}
<svg
    {{ $attributes->merge(['class' => $size === 'md' ? 'h-4 w-4' : 'h-5 w-5']) }}
    fill="none"
    viewBox="0 0 24 24"
    stroke="currentColor"
    stroke-width="2"
    aria-hidden="true"
>
    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z" />
</svg>
