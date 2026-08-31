{{--
    CSV layout-picker row — the follow-on chip set the bank connector
    step reveals when the reader picks the CSV format chip. CSV is the
    only bank-statement format that does not describe itself, so the
    reader names which column layout their export uses before the drop
    zone unlocks.

    Props:
      :layouts   — the layouts the parent step offers, each a
                     {format, label} pair resolved from the CSV preset
                     registry. A label is the preset's own data; no name
                     is written into this markup.
      :selected  — the picked layout's source format key, or null if the
                     reader has not yet picked.

    The row uses the existing `.format-chip-button` styling shipped on
    the format-chip row so the reader reuses one interaction model:
    pick-a-chip. Each button calls `setCsvLayout` on the parent
    component, which is what makes the picked layout the format the
    import runs as; arrow-key navigation comes from the surrounding
    `role="radiogroup"`.
--}}
@use('Modules\Core\Public\Support\Lang')
@props([
    'layouts' => [],
    'selected' => null,
])
@php
    /** @var list<array{format: string, label: string}> $layouts */
    /** @var string|null $selected */
@endphp

<div class="format-chips csv-bank-picker-row" role="radiogroup" aria-label="{{ Lang::get('onboarding::connect_bank.csv_picker_aria') }}">
    <span class="format-chips-label">{{ Lang::get('onboarding::connect_bank.csv_picker_from') }}</span>
    @foreach ($layouts as $layout)
        <button
            type="button"
            class="format-chip-button"
            role="radio"
            aria-checked="{{ $selected === $layout['format'] ? 'true' : 'false' }}"
            wire:click="setCsvLayout('{{ $layout['format'] }}')"
        >
            <x-onboarding::format-chip :label="$layout['label']" :recommended="$selected === $layout['format']" />
        </button>
    @endforeach
</div>
