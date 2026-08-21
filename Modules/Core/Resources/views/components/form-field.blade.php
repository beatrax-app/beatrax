@props([
    'label',                 // Required. The visible label text — passed in, never built here.
    'name',                  // Required. The validation key the error line reads, and the control's name=.
    'fieldId' => null,       // The control's id and the label's for=. Defaults to `name`.
    'type' => 'text',        // 'select' and 'textarea' switch the element; anything else is an input type.
    'hint' => null,          // The small line under the control. Gets an id so it can be described-by.
    'size' => 'sm',          // 'base' is 16px, the size that stops iOS zooming the viewport on focus.
    'tone' => 'neutral',     // 'danger' recolours the border and focus ring for a destructive confirm.
])

{{--
    One labelled form field: its label, its control, its hint and its error.

    Around sixty of these were written out by hand, and the control's class
    string had drifted into six spellings that disagreed about the light
    background (bg-slate-50 vs bg-white), the focus ring offset, and which
    dark: variants came along. The nineteen-occurrence majority — the
    bg-slate-50 spelling every sign-in, sign-up and password screen uses — is
    the one kept here; the minority sites now look like it.

    THE BINDING IS NOT A PROP. There is no `model` prop re-rendering a bare
    wire:model from a string, because that spelling can only reproduce the
    bare directive and would silently swallow the modifier. This codebase runs
    wire:model, .live, .blur and .live.debounce.300ms in different places, and
    a dropped modifier changes WHEN the component updates — a regression that
    still renders, still passes a render test, and breaks the screen. Instead
    the caller writes the real directive on the component tag, exactly as it
    would on a bare control — wire:model.live.debounce.300ms="query" — and
    every wire:* attribute is forwarded verbatim to the control below,
    modifier and all. Everything else in the bag — placeholder, autocomplete,
    inputmode, step, min, max, required, disabled, autofocus, x-on:*, style,
    data-* — rides along the same way.

    `name` is explicit rather than parsed back out of the wire:model
    expression: parsing would have to strip modifiers off the attribute name
    AND cope with wire:model="form.thing", where the validation key and the
    property path are the same string but neither is guessable from the id.

    `class` merges onto the CONTROL, not the wrapper, because that is what
    call sites actually vary — max-w-xs on the settings screen, font-mono on
    the recovery-code field. Pass classes that ADD to the default; a class
    that contradicts one of the defaults leaves both in the attribute and lets
    stylesheet order decide, which is not a decision the call site controls.

    Which is exactly why `size` and `tone` are props. Both replace a default
    rather than compete with it: text-base against text-sm, or a rose ring
    against the slate one, are the contradicting pairs that merging cannot
    settle. size="base" is not a look — Safari zooms the whole viewport when a
    focused control is under 16px, so every PIN and passphrase field that
    lives in a phone modal needs it to stay put.
--}}

@php
    $fieldId ??= $name;

    // The bag is read rather than reached for through @error, which would
    // fatal wherever ShareErrorsFromSession has not run — Blade::render in a
    // test, a mailable. A field with nothing to say about validation should
    // still draw itself.
    $errorMessage = isset($errors) && $errors->has($name) ? $errors->first($name) : null;
    $hintId = $hint !== null ? $fieldId.'-hint' : null;
    $errorId = $errorMessage !== null ? $fieldId.'-error' : null;

    // A caller that names its own descriptor has already decided what
    // describes the field — the sign-up password points at the live
    // requirement checklist, not at the line under it — so that wins over the
    // hint. The error id is appended either way: it must always be announced.
    $describedBy = implode(' ', array_filter([$attributes->get('aria-describedby') ?? $hintId, $errorId]));

    $fieldText = $size === 'base' ? 'text-md' : 'text-sm';
    $fieldTone = $tone === 'danger'
        ? 'border-rose-300 focus-visible:ring-rose-500 dark:border-rose-800'
        : 'border-slate-200 focus-visible:ring-slate-900 dark:border-slate-700 dark:focus-visible:ring-slate-100';

    $bindings = $attributes->whereStartsWith('wire:');
    $passthrough = $attributes->whereDoesntStartWith('wire:')->except(['class', 'aria-describedby']);
    $controlClass = $attributes->only('class')->merge([
        'class' => 'block w-full rounded-md border bg-slate-50 px-3 py-2 text-slate-900 placeholder:text-slate-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 dark:bg-slate-900 dark:text-slate-100 '.$fieldText.' '.$fieldTone,
    ]);
@endphp

<div class="space-y-1">
    <label for="{{ $fieldId }}" class="block text-sm text-slate-900 dark:text-slate-100">{{ $label }}</label>

    @if ($type === 'select')
        <select
            id="{{ $fieldId }}"
            name="{{ $name }}"
            @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
            @if ($errorId !== null) aria-invalid="true" @endif
            {{ $bindings }}
            {{ $passthrough }}
            {{ $controlClass }}
        >{{ $slot }}</select>
    @elseif ($type === 'textarea')
        <textarea
            id="{{ $fieldId }}"
            name="{{ $name }}"
            @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
            @if ($errorId !== null) aria-invalid="true" @endif
            {{ $bindings }}
            {{ $passthrough }}
            {{ $controlClass }}
        >{{ $slot }}</textarea>
    @else
        <input
            type="{{ $type }}"
            id="{{ $fieldId }}"
            name="{{ $name }}"
            @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
            @if ($errorId !== null) aria-invalid="true" @endif
            {{ $bindings }}
            {{ $passthrough }}
            {{ $controlClass }}
        />
    @endif

    @if ($hint !== null)
        <p id="{{ $hintId }}" class="text-xs text-slate-500 dark:text-slate-400">{{ $hint }}</p>
    @endif

    @if ($errorMessage !== null)
        <p id="{{ $errorId }}" role="alert" class="text-sm text-rose-600 dark:text-rose-500">{{ $errorMessage }}</p>
    @endif
</div>
