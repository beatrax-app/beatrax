{{--
    Format-chip — a small pill that names one of the file formats the
    user might have downloaded ("CAMT.053", "CSV", "MT940", "PDF").

    Props:
      :label        — chip text ("CAMT.053", "CSV", "PDF").
      :badge        — optional bracketed suffix text ("recommended",
                       "only format"). When omitted, no badge renders.
      :recommended  — when true, the chip + badge render in emerald
                       (the "[recommended]" CAMT.053 chip).

    Per UI-SPEC §"Color" #3 ("Format-chip 'recommended' badge — emerald-
    bg + emerald text only"), the emerald treatment is reserved for the
    single recommended option. The card step's PDF chip is muted
    ("[only format]" suffix), explicitly NOT emerald because it's a
    constraint, not a recommendation.
--}}
@props([
    'label',
    'badge' => '',
    'recommended' => false,
])
@php
    $chipClass = $recommended ? 'format-chip recommended' : 'format-chip';
@endphp

<span {{ $attributes->class([$chipClass]) }}>
    {{ $label }}
    @if ($badge !== '')
        <span class="format-chip-badge">[{{ $badge }}]</span>
    @endif
</span>
