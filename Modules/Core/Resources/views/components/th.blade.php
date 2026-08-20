@props([
    'align' => 'left',   // 'left' | 'right' — labels read left, money columns read right.
])

{{--
    One column header in a data table.

    Thirty-odd of these carried the same nine-class header string, and two
    things varied across them. Alignment did, legitimately: a money column
    is right-aligned and its label follows the figures, so `align` stays a
    call-site decision. Font weight also did, and that one is not a
    decision — seven headers had drifted to font-normal against twenty-seven
    at font-medium, so the majority weight is fixed here and the drifted
    seven come with it.

    scope="col" is a merge default rather than a literal: everything this
    renders is a column header by construction, and the seven drifted
    headers had lost the attribute entirely.
--}}
<th {{ $attributes->merge([
    'scope' => 'col',
    'class' => 'px-4 py-2 '.($align === 'right' ? 'text-right' : 'text-left').' text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400',
]) }}>{{ $slot }}</th>
