{{--
    CSV bank-picker row — the follow-on chip set the bank connector
    step reveals when the user picks the CSV format chip. CSV is the
    only ambiguous bank-statement format, so the user names which bank
    exported their CSV before the drop zone unlocks.

    Props:
      :selected   — the currently-picked bank format key ("asn-csv" or
                     "ing-csv"), or null if the user has not yet picked.

    The row uses the existing `.format-chip-button` styling shipped on
    the format-chip row so the user reuses one interaction model:
    pick-a-chip. Each button dispatches a server-side property set on
    the parent component (`selectedBankFormatHint`); arrow-key
    navigation comes from the surrounding `role="radiogroup"`.
--}}
@use('Modules\Ingestion\Public\Enums\SourceFormat')
@use('Modules\Core\Public\Support\Lang')
@props([
    'selected' => null,
])
@php
    /** @var string|null $selected */
@endphp

<div class="format-chips csv-bank-picker-row" role="radiogroup" aria-label="{{ Lang::get('onboarding::connect_bank.csv_picker_aria') }}">
    <span class="format-chips-label">{{ Lang::get('onboarding::connect_bank.csv_picker_from') }}</span>
    <button
        type="button"
        class="format-chip-button"
        role="radio"
        aria-checked="{{ $selected === SourceFormat::AsnCsv->value ? 'true' : 'false' }}"
        wire:click="$set('selectedBankFormatHint', '{{ SourceFormat::AsnCsv->value }}')"
    >
        <x-onboarding::format-chip label="ASN" :recommended="$selected === SourceFormat::AsnCsv->value" />
    </button>
    <button
        type="button"
        class="format-chip-button"
        role="radio"
        aria-checked="{{ $selected === SourceFormat::IngCsv->value ? 'true' : 'false' }}"
        wire:click="$set('selectedBankFormatHint', '{{ SourceFormat::IngCsv->value }}')"
    >
        <x-onboarding::format-chip label="ING" :recommended="$selected === SourceFormat::IngCsv->value" />
    </button>
</div>
