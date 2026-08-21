@props([
    'heading',        // Required. What is not here yet, said as a noun phrase.
    'body',           // Required. The line under it: why it is empty, or what fills it.
    'level' => 'h2',  // The page's own h1 is the page header, so a section under it starts at h2.
])

{{--
    The centred block a list shows when it has nothing to show: heading,
    one line underneath, and whatever gets the user out of the empty state.

    Not every empty state is this. A single sentence stays a single <p> —
    a component around one line of prose is worse than the line. This is
    for the ones that had grown a heading and a way forward, and had drifted
    apart doing it: text-xl beside text-lg beside text-2xl, mt-8 beside
    mt-4 beside no gap at all, a tinted card here and a plain surface there.

    `level` exists because the right heading level depends on what the block
    sits under: an empty state that stands in for a whole page, like the one
    on the migrations index, IS that page's h1, while one inside a section
    that already has a heading is not.

    The way out is the slot, so this holds no action of its own — the button
    keeps its wire:click, and the link keeps its href, at the call site.
--}}
<div {{ $attributes->merge(['class' => 'rounded-lg border border-slate-200 bg-slate-50 p-6 text-center dark:border-slate-700 dark:bg-slate-900']) }}>
    <{{ $level }} class="text-xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">{{ $heading }}</{{ $level }}>
    <p class="mx-auto mt-2 max-w-md text-sm text-slate-500 dark:text-slate-400">{{ $body }}</p>
    @if ($slot->isNotEmpty())
        <div class="mt-6 flex flex-wrap items-center justify-center gap-3">{{ $slot }}</div>
    @endif
</div>
