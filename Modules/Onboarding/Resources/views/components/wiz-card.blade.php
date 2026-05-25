{{--
    Wizard card surface — the 620px-max centered article that hosts the
    active step's body. The single `:wide` prop relaxes the max-width to
    1120px for the first-import preview step (UI-SPEC §"Density rules"
    locked exception), which needs the room for the preview table.

    The card carries the calm-slate token-bound border/shadow/radius. No
    inline Tailwind utilities — the `.wiz-card` rules in app.css are the
    single source of truth so a future token flip (e.g. dark-mode
    surface tint) updates every wizard surface in one place.
--}}
@props(['wide' => false])

<article {{ $attributes->class(['wiz-card', 'wiz-card--wide' => (bool) $wide]) }}>
    {{ $slot }}
</article>
