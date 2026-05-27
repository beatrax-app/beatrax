{{--
    Frame — bordered card primitive used to wrap Overview-tab sections
    on the counterparty profile (Categories, Recurring, Funding chain,
    Recent activity) and on the index/triage surfaces wherever a
    grouped block needs the same visual chrome.

    Variants:
      - default — surface-on-page, `--space-4` padding (`.frame`)
      - tight   — denser padding for nested frames (`.frame-tight`)
      - surface — subtle-surface fill for "this sits inside another frame" stacks (`.frame.surface`)

    Props:
      variant — string; one of `default` | `tight` | `surface`
--}}
@props([
    'variant' => 'default',
])
@php
    $variantClass = match ($variant) {
        'tight' => 'frame frame-tight',
        'surface' => 'frame surface',
        default => 'frame',
    };
@endphp
<section {{ $attributes->merge(['class' => $variantClass]) }}>
    {{ $slot }}
</section>
