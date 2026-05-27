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
        'merchant' => ['class' => 't-merchant', 'label' => 'Merchant'],
        'personal' => ['class' => 't-personal', 'label' => 'Personal'],
        'bank' => ['class' => 't-bank', 'label' => 'Bank'],
        'government' => ['class' => 't-gov', 'label' => 'Government'],
        'self_account' => ['class' => 't-self', 'label' => 'Self'],
        'unknown' => ['class' => 't-unknown', 'label' => 'Unknown'],
    ];

    $resolved = $typeLabels[$type] ?? $typeLabels['unknown'];
@endphp
<span
    {{ $attributes->merge(['class' => 'type-chip '.$resolved['class']]) }}
    aria-label="Counterparty type: {{ $resolved['label'] }}"
>
    {{ $resolved['label'] }}
</span>
