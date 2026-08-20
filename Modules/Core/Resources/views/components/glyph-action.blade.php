@props([
    'label',                 // Required. Becomes both the accessible name and the tooltip.
    'tone' => 'neutral',     // 'neutral' | 'danger' — danger for destructive row actions.
])

{{--
    One inline action glyph, used everywhere an icon stands in for a verb.

    Twenty icon-only buttons carried seventeen different class strings between
    them, so the same action looked like a different control on every screen —
    and several carried no styling at all, rendering as a bare character that
    read as decoration rather than as something you could press.

    The affordance is the point: a surface, a border and a pressed state, at a
    size a thumb can find. The coarse-pointer rule in app.css gives it a 44px
    reach without inflating the drawn box.

    Pass the icon as the slot. Everything else — wire:click, x-on:click,
    :disabled — forwards through $attributes.
--}}
<button
    type="button"
    {{ $attributes->merge(['class' => 'glyph-action'.($tone === 'danger' ? ' glyph-action--danger' : '')]) }}
    aria-label="{{ $label }}"
    title="{{ $label }}"
>
    <span class="glyph-action__mark" aria-hidden="true">{{ $slot }}</span>
</button>
