@props([
    'level' => 'page',   // 'page' for a routed screen, 'section' for a landing page that wants more weight
])

{{--
    The h1 of a routed page. Two pages had set their own size in a style
    attribute and landed on --text-xl, so they wore a heading visibly smaller
    than the thirty-three either side of them, and a third sat at text-lg.
    Nothing was broken and no test could fail; they were simply not part of the
    decision the others were making.

    Extra classes merge, which is how the two callers that need `truncate` or
    `min-w-0` get them without restating the type.
--}}
@php
    $pageHeadingClass = match ($level) {
        'section' => 'text-3xl font-semibold tracking-tight text-slate-900 dark:text-slate-100',
        default => 'text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100',
    };
@endphp

<h1 {{ $attributes->merge(['class' => $pageHeadingClass]) }}>{{ $slot }}</h1>
