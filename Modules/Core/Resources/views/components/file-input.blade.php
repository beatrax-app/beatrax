@use('Modules\Core\Public\Support\Lang')
@props(['accept' => '', 'multiple' => false])
@php
    // Pulled out of the bag so the id and the for= are both literal here. The
    // id already reached the input through $attributes, and the label already
    // wrapped it, but neither association was visible to anything reading the
    // template — including the a11y scanner, which called the input orphaned.
    $fieldId = $attributes->get('id');
@endphp
{{--
    A file picker the product can translate.

    A bare file input renders chrome supplied by the engine — on Android
    "Choose File / No file chosen" — in English, whatever the page language is,
    and no attribute or stylesheet can reach it. So the real control is hidden
    inside the label element, which keeps the native click-to-open behaviour
    and the keyboard and screen-reader semantics, and the button and filename
    are drawn here instead. Same technique the wizard's drop-zone already uses
    (`.drop-zone-input`).

    Element names are spelled out rather than written as tags: the a11y scanner
    parses this comment as markup, and a quoted tag reads to it as a real
    control with no label — which is what it flagged before this was reworded.

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
    <label
        @if ($fieldId !== null) for="{{ $fieldId }}" @endif
        class="inline-flex cursor-pointer items-center rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-900 hover:bg-slate-50 focus-within:outline-none focus-within:ring-2 focus-within:ring-slate-900 focus-within:ring-offset-2 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:hover:bg-slate-800">
        <input
            type="file"
            class="drop-zone-input"
            x-on:change="read($event.target)"
            @if ($fieldId !== null) id="{{ $fieldId }}" @endif
            @if ($accept !== '') accept="{{ $accept }}" @endif
            @if ($multiple) multiple @endif
            {{ $attributes->except(['class', 'id']) }}
        />
        {{ Lang::get('core::components.file.choose') }}
    </label>

    <span class="text-sm text-slate-500 dark:text-slate-400" x-text="label || @js(Lang::get('core::components.file.none'))"></span>
</div>
