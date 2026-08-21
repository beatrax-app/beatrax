@props([
    'label',                 // Required. Becomes both the accessible name and the tooltip.
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
--}}
<button
    type="button"
    {{ $attributes->merge(['class' => 'emoji-action'.($tone === 'danger' ? ' emoji-action--danger' : '')]) }}
    aria-label="{{ $label }}"
    title="{{ $label }}"
>
    <span class="emoji-action__mark" aria-hidden="true">{{ $slot }}</span>
</button>
