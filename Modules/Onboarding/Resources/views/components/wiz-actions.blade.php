{{--
    Right-aligned action row inside a wizard card — hosts the step's
    Skip + Continue buttons (or the equivalent per-step CTAs).

    Pure layout primitive: `.wiz-actions` is a flex row with `gap: 8px`
    and `justify-content: flex-end`. Buttons live as direct children
    via the slot so per-step copy stays inside the step blade.
--}}
<div {{ $attributes->class(['wiz-actions']) }}>
    {{ $slot }}
</div>
