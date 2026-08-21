@use('Modules\Core\Public\Enums\Locale')
@use('Modules\Core\Public\Services\LocaleNegotiator')
@use('Modules\Core\Public\Support\Lang')
@props(['selected', 'labelled' => false, 'selectClass' => '', 'fieldId' => 'locale-switcher-select'])
{{--
    The 26 languages plus the System sentinel, for every surface that asks the
    reader which language to read in: both of locale-switcher's shells and the
    Settings screen. What differs between them is how the choice travels — a
    POST navigation, a Livewire binding, a wire:change action — and that is one
    attribute, so it arrives on the tag and lands in `$attributes` here.

    `selected` is the code the caller considers chosen, or the
    LocaleNegotiator::SYSTEM sentinel for "follows the system". The caller
    decides it because each surface knows a different thing, and the difference
    matters: a guest screen has only `session('locale')` to go on, while
    Settings holds the reader's STORED preference, which LocaleNegotiator ranks
    above the session. Read the session here instead and a signed-in reader
    with a stored language would be shown System while the app spoke German.

    The label is sr-only unless the caller says otherwise. A screen that also
    carries a country picker, or a settings card that heads the control with a
    visible label of its own, passes `labelled` to suppress this one.

    `fieldId` is a prop rather than a passthrough attribute because the label
    above has to name the same id; a caller keeps its own so an id that other
    screens, tests and tooling already point at does not move.
--}}
@unless ($labelled)
    <label class="sr-only" for="{{ $fieldId }}">{{ Lang::get('core::settings.language.label') }}</label>
@endunless
<select
    id="{{ $fieldId }}"
    class="{{ $selectClass }}"
    {{ $attributes }}
>
    @foreach (Locale::cases() as $locale)
        <option
            value="{{ $locale->value }}"
            lang="{{ $locale->value }}"
            @selected($selected === $locale->value)
        >{{ $locale->label() }}</option>
    @endforeach
    <option
        value="{{ LocaleNegotiator::SYSTEM }}"
        @selected($selected === LocaleNegotiator::SYSTEM)
    >{{ Lang::get('core::settings.language.system') }}</option>
</select>
