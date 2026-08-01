@use('Modules\Core\Public\Support\Lang')
{{--
    Type chip — categorical metadata badge for a counterparty's
    taxonomy. Renders the 5+1 type variants (merchant, personal, bank,
    government, self_account, unknown) with consistent shape and the
    per-type colour language declared in app.css `.t-*` rules.

    Aria-label spells out the type so screen readers carry the meaning
    even when colour cannot be perceived — colour alone never carries
    semantics on this surface.

    Props:
      type — string; one of merchant | personal | bank | government | self_account | unknown
--}}
@props([
    'type' => 'unknown',
])
@php
    $typeLabels = [
        'merchant' => ['class' => 't-merchant', 'label' => Lang::get('counterparties::components.type_chip.merchant')],
        'personal' => ['class' => 't-personal', 'label' => Lang::get('counterparties::components.type_chip.personal')],
        'bank' => ['class' => 't-bank', 'label' => Lang::get('counterparties::components.type_chip.bank')],
        'government' => ['class' => 't-gov', 'label' => Lang::get('counterparties::components.type_chip.government')],
        'self_account' => ['class' => 't-self', 'label' => Lang::get('counterparties::components.type_chip.self')],
        'unknown' => ['class' => 't-unknown', 'label' => Lang::get('counterparties::components.type_chip.unknown')],
    ];

    $resolved = $typeLabels[$type] ?? $typeLabels['unknown'];
@endphp
<span
    {{ $attributes->merge(['class' => 'type-chip '.$resolved['class']]) }}
    aria-label="{{ Lang::get('counterparties::components.type_chip.aria', ['type' => $resolved['label']]) }}"
>
    {{ $resolved['label'] }}
</span>
