@props([
    'href' => null,    // Set it and this renders an <a>. Fourteen of these navigate rather than act.
    'size' => 'md',    // md = px-4 py-2, the modal footer and the page action. sm = px-3 py-1.5, the card-header action.
    'block' => false,  // false | 'flex' (flex-1, one of a modal-footer pair) | 'full' (w-full, a lone action).
])

{{--
    The outline button beside a primary one: Cancel, Back, Close, Not now.

    Seventy of these across forty-one files each spelled out the same
    ~250-character class string, and the copies had drifted on nine separate
    axes at once. The majority spelling wins every axis, so the minority
    sites are the ones that move:

      hover:bg-slate-50   64 / -100 1 / no hover fill at all 6
      rounded-md          68 / rounded 3
      bg-white            56 / none 14
      font-medium         62 / none 6 / font-mono 3
      text-slate-900      50 / -700 12 / -500 6 / -600 2
      text-sm             57 / text-xs 14
      border-slate-300    43 / -200 28
      focus ring          49 / absent 22
      px-4 py-2           27 / px-3 py-1.5 26 / py-3 6 / smaller 12

    The one site not converted is the biometric "Remove" button, which is
    rose rather than slate. A tone is not something a caller can merge in:
    text-rose-600 and the base's text-slate-900 have equal specificity, so
    which one paints would be decided by the stylesheet's order.

    The dark half is the one axis where the count and the outcome disagree,
    so it is worth writing down. dark:border-slate-700 wins the marginal
    vote 47 to 18, but forty-seven of those sites pair it with a fill of
    dark:bg-slate-900 or -950, where a slate-700 line still reads. This
    component fills dark:bg-slate-800 — the eighteen-site spelling, and the
    only one that stays visible on all three dark surfaces the app paints
    (the slate-950 card, the slate-900 modal, and the page itself). Against
    a slate-800 fill a slate-700 border is a shade darker than the thing it
    is supposed to outline, so it disappears. The border takes the minority
    dark:border-slate-600 that the fill it sits on requires.

    `block` is a prop rather than a class the caller merges, because
    flex-1, w-full and the default all set layout at equal specificity —
    two of them on one element are resolved by the stylesheet's order, not
    the call site's. Naming it replaces the default instead of fighting it.

    The base is a centred inline-flex rather than the bare button box 32 of
    the sites used, because 16 of the 70 stretch (flex-1 or w-full) and a
    stretched flex row left-aligns its label unless something centres it.
    On a shrink-to-fit button justify-center has nothing to do, so the
    other 54 are unaffected.

    min-h-[44px] is deliberately NOT here: 50 of 70 do without it, and the
    20 desktop sites that want it merge it in as an ordinary class — a
    min-height and a padding do not compete.

    The coarse-pointer floor in app.css covers the BUTTON branch only; it
    names button/summary/[role=button] and deliberately excludes anchors,
    because a link inside a sentence cannot be 44px without breaking the
    line. So the <a> branch carries .tap-chip, which extends reach through
    a pseudo-element instead of inflating the box.
--}}
@php
    $secondaryButtonSize = $size === 'sm' ? 'px-3 py-1.5' : 'px-4 py-2';
    $secondaryButtonBlock = match ($block) {
        'flex' => ' flex-1',
        'full' => ' w-full',
        default => '',
    };
    $secondaryButtonClass = 'inline-flex items-center justify-center rounded-md border border-slate-300 bg-white '
        .$secondaryButtonSize
        .' text-sm font-medium text-slate-900 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 dark:hover:bg-slate-700 dark:focus-visible:ring-slate-100'
        .$secondaryButtonBlock;
@endphp

@if ($href !== null)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $secondaryButtonClass.' tap-chip']) }}>
        {{ $slot }}
    </a>
@else
    {{-- type="button" is a merge default rather than a literal: fifty-four of
         the seventy act on click, one submits, and that one caller only has to
         say so once. --}}
    <button {{ $attributes->merge(['type' => 'button', 'class' => $secondaryButtonClass]) }}>
        {{ $slot }}
    </button>
@endif
