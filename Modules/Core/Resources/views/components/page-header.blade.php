@props([
    'title',       // Required. The page's h1 — one per page, so the level is fixed here.
    'subtitle',    // Required. The single line of lede underneath it.
])

{{--
    The heading block a standalone page opens with.

    Six pages carried these three lines verbatim and the login page had
    already slipped to text-2xl — which is what a copied type scale does
    when nothing owns it. Both strings come from the call site; this holds
    only the scale, the colour and the gap between them.
--}}
<header {{ $attributes->merge(['class' => 'space-y-1']) }}>
    <x-core::page-heading level="section">{{ $title }}</x-core::page-heading>
    <p class="text-sm text-slate-500 dark:text-slate-400">{{ $subtitle }}</p>
</header>
