{{-- Status pill primitive. Variants: ok | warn | fail | muted.
     2px 8px padding; 6px dot prefix; tabular-nums for alignment.

     Used by run-card status + the queue worker pre-flight pill on
     the runner page; consumed by 16-06's queue health surface too.
--}}
@props([
    'variant' => 'muted',
    'label' => '',
])
@php
    $variantClasses = match ($variant) {
        'ok' => 'text-emerald-700 dark:text-emerald-300 bg-emerald-50 dark:bg-emerald-900/30 border-emerald-200 dark:border-emerald-800',
        'warn' => 'text-amber-700 dark:text-amber-300 bg-amber-50 dark:bg-amber-900/30 border-amber-200 dark:border-amber-800',
        'fail' => 'text-rose-700 dark:text-rose-300 bg-rose-50 dark:bg-rose-900/30 border-rose-200 dark:border-rose-800',
        default => 'text-slate-600 dark:text-slate-400 bg-slate-50 dark:bg-slate-900/30 border-slate-200 dark:border-slate-800',
    };
    $dotColor = match ($variant) {
        'ok' => 'bg-emerald-500',
        'warn' => 'bg-amber-500',
        'fail' => 'bg-rose-500',
        default => 'bg-slate-400',
    };
@endphp
<span {{ $attributes->merge([
    'class' => "inline-flex items-center gap-1.5 rounded border px-2 py-0.5 text-xs font-medium tabular-nums {$variantClasses}",
]) }}>
    <span class="inline-block h-1.5 w-1.5 rounded-full {{ $dotColor }}" aria-hidden="true"></span>
    {{ $label !== '' ? $label : $slot }}
</span>
