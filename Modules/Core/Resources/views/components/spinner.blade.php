@props([
    'size' => 'md',   // 'md' (h-4 w-4) beside text | 'sm' (h-3 w-3) inside a button
])

{{--
    The turning ring that says work is in flight.

    Eight of these existed in three unrelated implementations. Five were an
    inline <svg class="animate-spin">, two were an empty <span> given a
    border and spun with rounded-full border-2 border-t-*, and one was the
    character ↻ in a text span. The five SVGs did not agree with each other
    either: three drew a ring and two drew heroicons' arrow-path, and the
    three rings carried three different arc paths — the upstream one, and
    two shortenings of it.

    The ring wins on usage, five marks of the eight counting both spellings
    of it, so that is what this draws — in the upstream Tailwind circle+path
    form only one of the three ring copies still had whole. Size is the one
    real distinction: a spinner beside a sentence matches the text (h-4 w-4)
    and a spinner inside a button is smaller than the label it interrupts
    (h-3 w-3).

    currentColor replaces the hand-picked border shades. A spinner is the
    text going round; it should not need to know it is on a dark button.

    inline-block is part of the geometry, not an option. Preflight makes an
    svg display:block, and the copy in the calendar summary strip sits
    inline before its label — as a block it would take the line to itself.

    aria-hidden, because a spinner is drawn reassurance and the sentence
    beside it already says what is happening. Where one is the ONLY sign
    that anything is loading, the call site owes it a visually-hidden label;
    that is a copy decision and it is not made here.

    wire:loading, wire:target and spacing stay outside: what triggers a
    spinner, and how far it sits from the next thing, belong to the call
    site.
--}}
<svg
    {{ $attributes->merge(['class' => 'inline-block animate-spin '.($size === 'sm' ? 'h-3 w-3' : 'h-4 w-4')]) }}
    viewBox="0 0 24 24"
    fill="none"
    aria-hidden="true"
>
    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
</svg>
