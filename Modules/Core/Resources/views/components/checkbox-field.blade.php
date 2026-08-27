@props([
    'label',                 // Required. The visible text beside the box — passed in, never built here.
    'name' => null,          // The control's name=, for the rare checkbox that posts a real form.
    'fieldId' => null,       // The control's id and the label's for=. Defaults to `name`; null leaves both off.
    'hint' => null,          // A second, smaller line under the label. Still inside the label, still clickable.
    'align' => 'center',     // 'start' tops the box against a label that wraps to two lines.
    'tone' => 'neutral',     // 'danger' swaps the emerald accent for slate, for a checkbox on a warning surface.
])

{{--
    One labelled checkbox row: the box, the words beside it, and the optional
    smaller line under those.

    Thirteen labelled checkboxes existed in ten distinct spellings of the
    input's class attribute, and the largest agreement between any of them was
    three. Four of the thirteen turned out to be instant-apply settings
    toggles and are x-core::switch now; the nine that are genuinely form
    checkboxes render through here.

    Nobody noticed ten spellings because most of what they said does nothing.
    This app sets appearance:none on no checkbox and carries no forms plugin,
    so a native box ignores `rounded`, `border-slate-300` and
    `text-emerald-700` outright. Eight of the thirteen asked for an emerald
    checkbox; all thirteen rendered in the operating system's blue. `accent-*`
    is the property that actually paints one, so that is what this uses.

    Three defects came with the copies and are fixed here rather than at nine
    call sites. Seven boxes had no size at all and rendered at the user agent's
    ~13px; they are h-4 w-4 now, and the row carries min-h-6 so the label — the
    real target, since clicking it toggles the box — clears the 24px minimum on
    a phone. Four carried no focus style whatsoever, and four more named a ring
    COLOUR with no ring width beside it, which paints nothing: eight of the
    nine were relying on the browser's own outline. And the tax-year override
    in the rule form had no class, no id, and its label was a bare text node
    with nothing tying it to the box beyond sitting in the same element.

    The ring is the minority spelling on purpose. Seven of the thirteen wrote
    `focus:`, one wrote `focus-visible:`; `focus:` paints a ring when you click
    with a mouse, which is noise, and every other control in Core — the primary
    button, the form field — already rings on focus-visible only. A component
    is where that stops being a coin toss.

    THE BINDING IS NOT A PROP, for the reason x-core::form-field spells out:
    wire:model, .live and .live.debounce.300ms are all in use here and a
    swallowed modifier changes when the component updates without changing what
    renders. Write the real directive on the tag. Every non-class attribute —
    wire:*, x-*, autofocus, aria-label, data-testid — is forwarded to the input,
    because the input is the control. `class` is the exception and merges onto
    the LABEL, which is this component's root and the only part a call site
    still has a say in: text-left inside a centred modal, padding above the row.

    `hint` stays INSIDE the label rather than becoming an aria-describedby, so
    the second line is part of the accessible name and part of the click
    target. On a checkbox the extra line is usually the other half of the
    sentence, not an aside about it.
--}}
@php
    $fieldId ??= $name;

    $checkboxAccent = $tone === 'danger'
        ? 'accent-slate-900 focus-visible:ring-slate-900 dark:accent-slate-100 dark:focus-visible:ring-slate-100'
        : 'accent-emerald-600 focus-visible:ring-emerald-700 dark:accent-emerald-500';

    // mt-0.5, not the mt-1 three of the copies used: now that every box is a
    // fixed 16px against a 20px line, 2px centres it and 4px sits it low.
    $checkboxBox = 'h-4 w-4 shrink-0 focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 '
        .$checkboxAccent
        .($align === 'start' ? ' mt-0.5' : '');

    $checkboxBindings = $attributes->whereStartsWith('wire:');
    $checkboxPassthrough = $attributes->whereDoesntStartWith('wire:')->except(['class']);
@endphp

<label
    @if ($fieldId !== null) for="{{ $fieldId }}" @endif
    {{ $attributes->only('class')->merge(['class' => 'flex min-h-6 gap-2 '.($align === 'start' ? 'items-start' : 'items-center')]) }}
>
    <input
        type="checkbox"
        @if ($fieldId !== null) id="{{ $fieldId }}" @endif
        @if ($name !== null) name="{{ $name }}" @endif
        {{ $checkboxBindings }}
        {{ $checkboxPassthrough }}
        class="{{ $checkboxBox }}"
    />
    <span class="text-sm text-slate-700 dark:text-slate-300">
        {{ $label }}
        @if ($hint !== null)
            <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $hint }}</span>
        @endif
    </span>
</label>
