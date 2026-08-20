@props([
    'tag' => 'div',          // 'div' | 'section' | 'li' — a landmark or a list row still needs its own element.
    'padding' => 'normal',   // 'normal' (p-6) | 'tight' (p-4) — tight is for cards that repeat down a list.
])

{{--
    The bordered white panel almost every page is built out of.

    Forty of these across thirteen modules spelled out the same seven
    classes, and the copies had drifted in four directions at once: six had
    reached rounded-xl (against a hundred and twenty-nine rounded-lg
    elsewhere, so rounded-lg wins on usage) and paired it with a
    dark:border-slate-800 nothing else used; nine had dropped to p-4; four
    had become <section>; and the two dark: classes had been typed in both
    possible orders.

    Only two of those four are real distinctions. Padding is: a card that
    repeats down a list is tighter than a card that owns a page section, so
    `padding` keeps it. The element is: a landmark and a list row are not
    interchangeable with a div, so `tag` keeps it. Radius, border shade and
    class order are not distinctions at all, and they live here now.
--}}
<{{ $tag }} {{ $attributes->merge([
    'class' => 'rounded-lg border border-slate-200 bg-white '.($padding === 'tight' ? 'p-4' : 'p-6').' dark:bg-slate-950 dark:border-slate-700',
]) }}>{{ $slot }}</{{ $tag }}>
