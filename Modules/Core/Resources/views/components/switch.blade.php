@props([
    'on' => false,   // The state the track paints and the ARIA reports. Not the label.
    'label',         // Required. The accessible name — a bare track announces nothing.
])

{{--
    The on/off track a setting is flipped with.

    Fourteen of these were written out by hand and the markup never varied:
    the same button, the same .switch/.switch--on pair, the same single thumb
    span. What DID vary was the ARIA, and it varied three ways. Twelve sites
    said aria-pressed, two said role="switch" with aria-checked, and four more
    screens rendered the same logical setting as a styled checkbox instead —
    so a screen reader announced "button, pressed", "switch, on" and
    "checkbox, checked" for three settings sitting on adjacent screens.

    This takes the MINORITY spelling on purpose. aria-pressed describes a
    toggle button — Bold in a text editor, a control that acts. role="switch"
    describes a thing that is on or off, which is what every one of these
    fourteen actually is, and it announces "on"/"off" rather than
    "pressed"/"not pressed". The count favoured aria-pressed; the meaning does
    not, and a component is where that gets settled once.

    The class is built here rather than merged so a caller cannot spell
    switch--on a fourth way; three spellings of the conditional already
    existed and all three rendered identically, which is exactly the kind of
    difference that costs a reader time and buys nothing.

    Focus is handled by .switch:focus-visible in app.css, so no ring utility
    is added here — one would sit on top of the outline that already exists.
--}}

<button
    type="button"
    role="switch"
    aria-checked="{{ $on ? 'true' : 'false' }}"
    aria-label="{{ $label }}"
    {{ $attributes->merge(['class' => 'switch'.($on ? ' switch--on' : '')]) }}
>
    <span class="switch__thumb"></span>
</button>
