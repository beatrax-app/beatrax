@props([
    'value' => 0,          // How far along it is, in whatever unit $max is counted in.
    'max' => 100,          // What "full" means. Leave it when the call site already holds a percentage.
    'label' => null,       // The accessible name. A progressbar without one announces a bare number.
    'tone' => 'neutral',   // neutral | positive | warning | danger — the same four the alert and the status pill use.
    'width' => 'w-full',   // The track's width utility. A bar in a flex row needs a fixed one; a block-level bar does not.
    'indeterminate' => false,   // No known position — a running step, not a measured one.
])

{{--
    The track-and-fill bar a screen uses to show how far along something is.

    Seven of these existed — dashboard spend, two goals cards, two mobile
    sync screens and two device-encryption steps — and every one was typed
    out again: track and fill both, twice each. The track colour was the
    only thing all seven agreed on. The height came out h-1 twice, h-1.5
    once and h-2 four times, so h-2 wins on usage; overflow-hidden split
    four to three, and it stays because a fill that can escape its own
    track is a bug rather than a look.

    The fill repeats the track's height in every copy. Here it is h-full,
    which is the same pixel and only one place to change.

    ARIA is the reason this is a component and not a snippet. The
    dashboard bar carried no role at all, and the two encryption bars
    carried an English aria-label in an app that ships two languages. The
    role and the three value attributes are fixed here so no future copy
    can be born without them, and `label` is a named prop so that leaving
    a bar unnamed has to be a decision.

    The tone names match x-core::alert and x-core::status-pill. A bar that
    means "you are over" and a pill that means "over budget" should not
    need two different words for the same red.

    `width` is a prop rather than something a caller merges in, because two
    Tailwind width classes on one element do not resolve by class order —
    equal specificity means the stylesheet's own order decides, so a summary
    card asking for w-20 against the default w-full would win or lose by
    accident. Naming it replaces the default instead of fighting it.

    An indeterminate bar drops aria-valuenow entirely, which is what tells a
    screen reader "running, position unknown" rather than the "0%" a zeroed
    value announces. The sliver animates via .beatrax-indeterminate, which
    already yields to prefers-reduced-motion.
--}}
@php
    $progressSpan = $max > 0 ? $max : 1;
    $progressPercent = round(max(0, min(100, ($value / $progressSpan) * 100)), 2);
    $progressFill = match ($tone) {
        'positive' => 'bg-emerald-500 dark:bg-emerald-400',
        'warning' => 'bg-amber-500 dark:bg-amber-400',
        'danger' => 'bg-rose-500 dark:bg-rose-400',
        default => 'bg-slate-900 dark:bg-slate-100',
    };
@endphp

<div
    role="progressbar"
    @unless ($indeterminate) aria-valuenow="{{ $value }}" @endunless
    aria-valuemin="0"
    aria-valuemax="{{ $max }}"
    @if ($label !== null) aria-label="{{ $label }}" @endif
    {{ $attributes->merge(['class' => 'h-2 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700 '.$width]) }}
>
    @if ($indeterminate)
        <div class="beatrax-indeterminate h-full w-1/3 rounded-full {{ $progressFill }}"></div>
    @else
        {{-- A determinate bar is polled, so without a transition it jumps a
             whole tick at a time and reads as a stutter rather than progress. --}}
        <div class="h-full rounded-full transition-[width] duration-300 {{ $progressFill }}" style="width: {{ $progressPercent }}%;"></div>
    @endif
</div>
