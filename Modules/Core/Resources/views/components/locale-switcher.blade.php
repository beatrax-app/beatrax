@use('Modules\Core\Public\Enums\Locale')
@use('Modules\Core\Public\Support\Lang')
@inject('translator', 'translator')
@props(['labelled' => false])
@php
    $wrapperClass = $labelled ? 'space-y-1' : '';
    $formClass = $labelled ? 'flex gap-2' : 'locale-switcher';
    $selectClass = $labelled ? 'locale-switcher-select w-full' : 'locale-switcher-select';
@endphp
{{--
    Pre-auth language switch for the welcome / signup / login surfaces.

    A select rather than one button per language: the row of buttons only fits
    while there are two of them, and adding a third would have to be designed
    around rather than just listed.

    Still a plain POST form: these screens are the first thing a fresh install
    renders, and the switch has to keep working if the JS bundle has not booted.
    Signed-in users keep using the richer Settings control, whose stored
    preference outranks this session key.

    The submit handler exists because a native form POST does not survive the
    mobile shell: NativePHP intercepts WebView requests, replays them into the
    embedded runtime, and loses the POST method, so Laravel answered 405 and
    the app sat in a redirect loop. `code` now lives on the select element
    below, so it is part of FormData and no longer depends on the submitter
    surviving. (Named rather than written as a tag: the HTML analyser does not
    parse Blade comments, and read a mention here as a real, unlabelled input.)

    fetch() IS intercepted reliably — it is the path every Livewire round-trip
    already takes — so submitting through it sidesteps both defects without
    changing the endpoint. Alpine absent, the native submit still runs: desktop
    keeps its no-JS guarantee, and the mobile WebView always has JS.

    `labelled` is for a screen that also carries a COUNTRY picker. There the
    two are a sentence apart and read alike, so the language one has to say in
    visible copy what it changes — and, as pointedly, what it does not.
--}}
<div class="{{ $wrapperClass }}">
@if ($labelled)
    <label class="block text-sm text-slate-900 dark:text-slate-100" for="locale-switcher-select">{{ Lang::get('core::settings.language.label') }}</label>
@endif
<form
    method="POST"
    action="{{ route('locale.switch') }}"
    class="{{ $formClass }}"
    x-data
    x-on:submit.prevent="beatraxSubmitPostForm($el, $event.submitter)"
>
    @csrf
    @unless ($labelled)
        <label class="sr-only" for="locale-switcher-select">{{ Lang::get('core::settings.language.label') }}</label>
    @endunless
    <select
        id="locale-switcher-select"
        name="code"
        class="{{ $selectClass }}"
        x-on:change="$el.form.requestSubmit()"
    >
        @foreach (Locale::cases() as $locale)
            <option
                value="{{ $locale->value }}"
                lang="{{ $locale->value }}"
                @selected($translator->getLocale() === $locale->value)
            >{{ $locale->label() }}</option>
        @endforeach
    </select>

    {{-- Submitting on change needs JS, so this stays in the markup for a
         first paint that has not booted yet — but once Alpine is alive the
         select submits itself and a second control is just a button that
         does what already happened. Deliberately NOT x-cloak'd: cloaking
         would hide it from the no-JS reader it exists for. --}}
    <button
        type="submit"
        class="locale-switcher-go"
        x-show="false"
    >{{ Lang::get('core::settings.language.apply') }}</button>
</form>
@if ($labelled)
    <p class="text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('core::settings.language.help') }}</p>
@endif
</div>
