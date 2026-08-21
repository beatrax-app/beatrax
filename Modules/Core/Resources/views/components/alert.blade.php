@props([
    'tone' => 'neutral',   // neutral | info | positive | warning | danger — the same set the status pill uses.
])

{{--
    The bordered block a screen uses to say something went wrong, or right.

    Nine of these existed across upload, sign-in, recovery and the dev console,
    and no two were spelled the same way: rounded / rounded-md / rounded-xl,
    p-3 / p-4, border-200 / border-300, and dark borders picked from three
    different steps. Nine blocks, nine class strings.

    The tone names match x-core::status-pill deliberately. An app with positive
    pills and success alerts is the same drift one level up.

    `info` exists because three Sync blocks say "this is running" or "here is
    an option you have" — neither good news nor bad, and painting them amber
    made a routine offer look like a problem.

    No role is set here. An error banner wants role="alert" and a quiet note
    wants nothing at all, so the call site keeps whatever semantics it had.
--}}
@php
    $alertTone = match ($tone) {
        'danger' => 'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-800 dark:bg-rose-950 dark:text-rose-200',
        'warning' => 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200',
        'info' => 'border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-800 dark:bg-sky-950 dark:text-sky-200',
        'positive' => 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-200',
        default => 'border-slate-200 bg-slate-50 text-slate-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300',
    };
@endphp

<div {{ $attributes->merge(['class' => 'rounded-md border p-4 text-sm '.$alertTone]) }}>
    {{ $slot }}
</div>
