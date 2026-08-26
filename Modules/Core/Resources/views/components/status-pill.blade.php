@props([
    'tone' => 'neutral',   // 'neutral' | 'positive' | 'warning' | 'danger'
])

{{--
    The small rounded label that states what something IS: connected,
    expired, archived, over budget.

    Around forty-five of these existed and essentially no two were spelled
    the same way — three padding scales, two text sizes and six colour
    families between them. None of that variation meant anything: a pill
    that says "Archived" and a pill that says "Expired" differ in what they
    report, not in how big they are.

    So geometry is fixed here and only the tone is a call-site decision,
    narrowed to the four a status can actually be. shrink-0 and
    whitespace-nowrap are part of that geometry rather than options: a pill
    is a single short word, and the copies that lacked them wrapped into a
    two-line blob inside a flex row.

    max-w-full is what keeps that true without taking the page sideways at the
    reader's accessibility text sizes, where "4 over budget" measured 295px of
    a 216px card. A cap is not a shrink: the pill still refuses to be squeezed,
    and only wraps once there is no line left to wrap onto.

    The light/dark pairs follow the amber one this replaced in the budgets
    glance card. Neutral keeps dark:bg-slate-800 instead of the parallel
    dark:bg-slate-900/40 — that is the spelling nine of the ten slate pills
    already used, and 900/40 over a slate-950 card is not a visible pill.

    This is not the `.status-pill` CSS class, which is a different
    primitive with a dot and its own coarse-pointer rules.
--}}
@php
    $toneClasses = match ($tone) {
        'positive' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300',
        'warning' => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
        'danger' => 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-300',
        default => 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
    };
@endphp
<span {{ $attributes->merge([
    'class' => "inline-flex max-w-full shrink-0 items-center whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium {$toneClasses}",
]) }}>{{ $slot }}</span>
