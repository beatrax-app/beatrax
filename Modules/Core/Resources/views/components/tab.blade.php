@props([
    'active' => false,   // Whether this tab's panel is the one currently shown.
])

{{--
    One button in a tab strip: the underline that marks which panel you are
    looking at.

    Four of these existed across notifications, drift alerts and forecasting,
    and the styling was retyped at each one — same seven utilities, same two
    dark: pairs, spelled in three orders, with the ternary or the @class array
    rebuilt from scratch every time. Nothing in that repetition was a
    decision. Which tab is selected is the only thing that varies, so
    `active` is the only prop.

    It deliberately does not own the strip. role="tablist", the label on it,
    id, aria-controls, wire:click and the loop that knows the tabs all stay
    at the call site, because the component that holds the state is the one
    that can name the panels. This is the button and nothing above it.

    The focus ring is new. None of the four copies had one — a keyboard user
    tabbing along the strip saw nothing move — so the app's ordinary ring is
    part of the button now rather than something each strip remembers.

    Chip-shaped filter rows (bordered, filled when active) are a different
    control that happens to also use role="tab", and they are not this.
--}}
<button
    type="button"
    role="tab"
    aria-selected="{{ $active ? 'true' : 'false' }}"
    {{ $attributes->merge([
        'class' => 'border-b-2 px-3 py-2 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:focus-visible:ring-slate-100 '.($active
            ? 'border-slate-900 font-medium text-slate-900 dark:border-slate-100 dark:text-slate-100'
            : 'border-transparent text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-100'),
    ]) }}
>{{ $slot }}</button>
