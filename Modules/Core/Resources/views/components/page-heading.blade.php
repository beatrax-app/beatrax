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

    The `tip` slot takes an x-core::help-tip and moves the type onto a block
    that holds the heading and the mark both. The mark is lifted onto the cap
    height of its OWN font: beside an h1 that carried its own size it inherited
    the body's instead and landed 6px low. It stays outside the <h1> rather
    than inside it — a button in there is read out as part of the heading, and
    the tip's panel is a <div>, which an <h1> may not contain.

    The space between them is non-breaking. Measured at 411px: the Turkish
    recurring title is one line wide and the mark is not, so a breakable space
    dropped the mark alone onto a second line under the heading. Glued, a
    heading that runs out of room takes its last word down with the mark. The
    slot is trimmed for the same reason — a caller's newline left inside the
    <h1> is a breakable space, and it would sit in front of the glue.
--}}
@php
    $pageHeadingClass = match ($level) {
        'section' => 'text-3xl font-semibold tracking-tight text-slate-900 dark:text-slate-100',
        default => 'text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100',
    };
@endphp

@isset($tip)
    <div class="heading-with-tip {{ $pageHeadingClass }}">
        <h1 {{ $attributes->merge(['class' => 'inline']) }}>{!! trim($slot) !!}</h1>&nbsp;{{ $tip }}
    </div>
@else
    <h1 {{ $attributes->merge(['class' => $pageHeadingClass]) }}>{{ $slot }}</h1>
@endisset
