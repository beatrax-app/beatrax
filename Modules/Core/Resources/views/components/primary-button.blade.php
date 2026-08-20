@props([
    'href' => null,   // Set it and this renders an <a>. A primary action that navigates is still the primary action.
])

{{--
    The full-width action that closes a form: sign in, save, parse, import.

    Eleven of these across five modules each spelled out the same 200-character
    emerald class string, and the copies had already begun to disagree about the
    order of their dark: variants. The label is the slot.

    Four of the eleven navigate rather than submit, and were <a> tags carrying a
    redundant inline-block. Passing href renders the anchor, so the same control
    can be either without the class string being written out twice more.

    `type` is a merge default rather than a literal, so a caller that acts on
    click instead of submitting can pass type="button" without emitting the
    attribute twice.
--}}
@php
    $primaryButtonClass = 'w-full bg-emerald-600 hover:bg-emerald-700 text-white font-medium rounded-md py-2 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2 dark:hover:bg-emerald-400 dark:bg-emerald-500';
@endphp

@if ($href !== null)
    {{-- inline-block and text-center because an anchor is neither by default,
         and this one has to fill the same box the button does. --}}
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $primaryButtonClass.' inline-block text-center']) }}>
        {{ $slot }}
    </a>
@else
    <button {{ $attributes->merge(['type' => 'submit', 'class' => $primaryButtonClass]) }}>
        {{ $slot }}
    </button>
@endif
