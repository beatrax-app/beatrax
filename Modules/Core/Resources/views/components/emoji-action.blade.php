@props([
    'label',                 // Required. Becomes both the accessible name and the tooltip.
    'caption' => null,       // The verb the hold tooltip shows. Defaults to $label; pass a shorter one where the label is a phrase.
    'tone' => 'neutral',     // 'neutral' | 'danger' — danger for destructive row actions.
])

{{--
    One inline action button, used everywhere an icon stands alone AS the verb.

    Its mark is an emoji, deliberately. A sole action has no words to lean on,
    and the text glyphs this replaced — ✎, ⊟, ↑, ⇄ — are drawn from the body
    font at whatever weight and baseline it gives them, so they landed at 7-10px
    wide, sat off-centre, and read as decoration. An emoji is a picture: same
    size, same alignment, recognisable without a label.

    Glyphs still belong where there is surrounding meaning to lean on — as a
    prefix inside a button that also has text, and as nav icons.

    Twenty icon-only buttons carried seventeen different class strings before
    this existed. Pass the emoji as the slot; wire:click, x-on:click and
    :disabled forward through $attributes.

    `title` is the desktop tooltip and fires on nothing a finger does, so a
    touch screen reads the same word by pressing and holding — the gesture
    Android's own toolbars use for exactly this. The wrapper carries the
    handlers rather than the button because the click has to be swallowed in
    the CAPTURE phase, before it can reach a wire:click and archive something
    the reader was only trying to read. It is display:contents, so the button
    stays the flex item its row lays out.

    The tip teleports to <body>: the calendar day panel is transformed and
    scroll-clipped, and the pots list is overflow-hidden, so a tip rendered in
    place would be cut off in both. It outlives both the OS callout and the
    release, because a tip that only exists while the thumb covers it is one
    that never gets read.
    `.docs/conventions/an-icon-only-action-says-its-verb-on-touch.md` holds why.
--}}
<span
    class="emoji-action-hold"
    x-data="emojiActionHold()"
    x-on:pointerdown="press($event)"
    x-on:pointermove="drift($event)"
    x-on:pointerup="release()"
    x-on:pointercancel="reset()"
    x-on:click.capture="guard($event)"
    x-on:contextmenu="callout($event)"
>
    <button
        type="button"
        {{ $attributes->merge(['class' => 'emoji-action'.($tone === 'danger' ? ' emoji-action--danger' : '')]) }}
        aria-label="{{ $label }}"
        title="{{ $label }}"
    >
        <span class="emoji-action__mark" aria-hidden="true">{{ $slot }}</span>
    </button>

    <template x-teleport="body">
        <span class="emoji-action__tip" x-show="shown" x-cloak x-ref="tip" aria-hidden="true">{{ $caption ?? $label }}</span>
    </template>
</span>
