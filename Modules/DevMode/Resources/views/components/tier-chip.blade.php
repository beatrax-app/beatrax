@use('Modules\Core\Public\Support\Lang')
{{-- Tier chip primitive. Variants: safe | destructive.
     2px 7px padding; 10.5px uppercase JetBrains Mono.
     Emerald (safe) / rose (destructive) per UI-SPEC § Cross-cutting.
--}}
@props([
    'tier' => 'safe',
])
@php
    $variantClasses = match ($tier) {
        'destructive' => 'text-rose-700 dark:text-rose-300 bg-rose-50 dark:bg-rose-900/30 border-rose-200 dark:border-rose-800',
        default => 'text-emerald-700 dark:text-emerald-300 bg-emerald-50 dark:bg-emerald-900/30 border-emerald-200 dark:border-emerald-800',
    };
    $label = $tier === 'destructive' ? Lang::get('dev::common.tier.destructive') : Lang::get('dev::common.tier.safe');
@endphp
<span {{ $attributes->merge([
    'class' => "inline-flex items-center rounded border px-1.5 py-0.5 font-mono text-[10.5px] uppercase tracking-wider {$variantClasses}",
]) }}>
    {{ $label }}
</span>
