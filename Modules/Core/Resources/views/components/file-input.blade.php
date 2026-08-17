@use('Modules\Core\Public\Support\Lang')
@props(['accept' => '', 'multiple' => false])
{{--
    A file picker the product can translate.

    A bare `<input type="file">` renders chrome supplied by the engine — on
    Android "Choose File / No file chosen" — in English, whatever the page
    language is, and no attribute or stylesheet can reach it. So the real
    input is hidden inside a <label>, which keeps the native click-to-open
    behaviour and the keyboard and screen-reader semantics, and the button and
    filename are drawn here instead. Same technique the wizard's drop-zone
    already uses (`.drop-zone-input`).

    The chosen filename is rendered from the input's own FileList rather than
    tracked in component state, so it stays correct when the picker is
    cancelled or the form is reset.
--}}
<div
    x-data="{
        label: '',
        read(input) {
            const files = input.files;
            if (! files || files.length === 0) { this.label = ''; return; }
            this.label = files.length === 1
                ? files[0].name
                : @js(Lang::get('core::components.file.count')).replace(':count', files.length);
        },
    }"
    {{ $attributes->only('class')->class(['flex flex-wrap items-center gap-2']) }}
>
    <label class="inline-flex cursor-pointer items-center rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-900 hover:bg-slate-50 focus-within:outline-none focus-within:ring-2 focus-within:ring-slate-900 focus-within:ring-offset-2 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:hover:bg-slate-800">
        <input
            type="file"
            class="drop-zone-input"
            x-on:change="read($event.target)"
            @if ($accept !== '') accept="{{ $accept }}" @endif
            @if ($multiple) multiple @endif
            {{ $attributes->except('class') }}
        />
        {{ Lang::get('core::components.file.choose') }}
    </label>

    <span class="text-sm text-slate-500 dark:text-slate-400" x-text="label || @js(Lang::get('core::components.file.none'))"></span>
</div>
