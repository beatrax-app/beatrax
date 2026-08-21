@props([
    'width' => '5xl',   // '3xl' | '5xl' | '6xl' — how wide the reading column is allowed to get.
])

{{--
    The full-height background and centred column a routed page mounts its
    Livewire component inside.

    Seventeen route views wrote this nest out by hand and the inner container
    had drifted into five spellings: max-w-5xl px-8 py-12 (5), max-w-3xl px-6
    py-16 (5), max-w-3xl px-8 py-12 (2), max-w-6xl px-8 py-12 (2) and
    max-w-5xl px-6 py-16 (2). Only the max-width is a decision anybody made on
    purpose — a counterparty table wants six columns of room, an import wizard
    wants a reading measure — so that is the prop. The gutter and the vertical
    rhythm are not: px-8 py-12 carries ten against px-6 py-16's seven, so the
    import and migration pages come to it.

    It renders a div and not a <main>. Sixteen of the seventeen opened a
    <main> here, but layouts.app already wraps @yield('content') in one, so
    each of those pages shipped a main landmark nested inside a main landmark
    — two unlabelled "main" regions for a screen reader to choose between, and
    a nesting the HTML spec does not allow. The seventeenth,
    community/mystery-merchants, had the column but no <main>, and was the only
    one that came out right. The landmark stays in the layout and the column
    lives here, so a page has exactly one of each.

    `width` is a prop rather than something a caller merges in because two
    max-w utilities on one element have equal specificity, so stylesheet order
    would decide which won — the same reason x-core::progress-bar names its
    width instead of taking one.
--}}
@php
    $pageShellWidth = match ($width) {
        '3xl' => 'max-w-3xl',
        '6xl' => 'max-w-6xl',
        default => 'max-w-5xl',
    };
@endphp

<div {{ $attributes->merge(['class' => 'min-h-screen bg-white dark:bg-slate-950']) }}>
    <div class="mx-auto {{ $pageShellWidth }} px-8 py-12">
        {{ $slot }}
    </div>
</div>
