@props([
    'href' => null,    // Set it and this renders an <a>. Two of these navigate rather than act.
    'size' => 'md',    // md = px-4 py-2, the modal footer and the page action. sm = px-3 py-1.5, the inline row action.
    'block' => false,  // false | 'flex' (flex-1, one of a modal-footer pair) | 'full' (w-full, a lone action).
])

{{--
    The solid slate action: Save, Pair, Continue, Run. The emerald
    x-core::primary-button closes a whole form; this one is the committing
    action everywhere else, which is why there are more of them.

    Fifty-nine of these across twenty-three files, and bg-slate-900 with
    text-white over dark:bg-slate-100 with dark:text-slate-900 was the only
    thing all fifty-nine agreed on. Every other axis had drifted, and the
    majority spelling wins each:

      text-sm             56 / text-xs 1
      rounded-md          55 / rounded-lg 1 / rounded 1
      px-4                46 / px-3 10 / px-2 1
      hover:bg-slate-700  45 / -800 14
      focus ring          43 / absent 14
      font-medium         38 / font-semibold 21
      py-2                33 / py-2.5 8 / py-3 6 / py-1.5 9 / py-1 1
      dark:hover:bg-slate-200  28 / dark:hover:bg-white 23 / absent 5 / -300 3

    dark:hover:bg-slate-200 beat dark:hover:bg-white by five, and the tie
    would have been worth breaking the same way regardless: the resting
    fill is dark:bg-slate-100, so hovering to white is a step of one shade
    where every other button in the app moves two, and on an OLED phone it
    is the brightest rectangle the app can draw.

    `block` is a prop rather than a class the caller merges, because
    flex-1, w-full and the default all set layout at equal specificity —
    two of them on one element are resolved by the stylesheet's order, not
    the call site's. Naming it replaces the default instead of fighting it.

    The base is a centred inline-flex rather than the bare button box 31 of
    the sites used, because 21 of the 59 stretch (flex-1 or w-full) and a
    stretched flex row left-aligns its label unless something centres it.
    On a shrink-to-fit button justify-center has nothing to do, so the
    other 38 are unaffected.

    min-h-[44px] is deliberately NOT here: 30 of 59 do without it, the
    coarse-pointer floor in app.css already guarantees 44px on touch, and
    the 27 desktop sites that want it merge it in as an ordinary class —
    a min-height and a padding do not compete. Seventeen sites carry their
    own disabled: classes for the same reason: what "unavailable" means
    differs per screen (dimmed, wait cursor, or a grey fill), so it rides
    the attribute bag rather than being decided here.
--}}
@php
    $neutralButtonSize = $size === 'sm' ? 'px-3 py-1.5' : 'px-4 py-2';
    $neutralButtonBlock = match ($block) {
        'flex' => ' flex-1',
        'full' => ' w-full',
        default => '',
    };
    $neutralButtonClass = 'inline-flex items-center justify-center rounded-md bg-slate-900 '
        .$neutralButtonSize
        .' text-sm font-medium text-white hover:bg-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200 dark:focus-visible:ring-slate-100'
        .$neutralButtonBlock;
@endphp

@if ($href !== null)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $neutralButtonClass.' tap-chip']) }}>
        {{ $slot }}
    </a>
@else
    {{-- type="button" is a merge default rather than a literal: forty-three of
         the fifty-nine act on click, twelve submit, and a submit caller only
         has to say so once. --}}
    <button {{ $attributes->merge(['type' => 'button', 'class' => $neutralButtonClass]) }}>
        {{ $slot }}
    </button>
@endif
