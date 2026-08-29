@props([
    'title',      // Required. The section's name.
    'level' => 2, // 2 for a section of the page, 3 for a section of a section.
])

{{--
    The heading a section opens with, and optionally the control that sits
    opposite it.

    A hundred and three h2s carried nineteen different class strings between
    them: text-base against text-lg against text-sm, semibold against medium,
    and four that had drifted into an uppercase eyebrow. This holds the
    thirty-five-site majority spelling.

    With no slot it renders the heading alone, so a section that never had a
    flex row does not gain one. Pass a slot and it becomes the row, with the
    action opposite the title — which is what the sites that DO have an action
    were each building by hand.

    `level` picks the tag and nothing else. Twenty-three h3s already carried
    this exact class string and could not use the component while it hardcoded
    h2: a subsection of a section is an h3 by document outline, and taking that
    away to reach the shared spelling is the wrong trade. The size is the
    section's, the level is the outline's, and they are separate questions.
--}}
@php
    $sectionHeadingClass = 'text-base font-semibold text-slate-900 dark:text-slate-100';
@endphp
@if ($slot->isEmpty())
    <h{{ $level }} {{ $attributes->merge(['class' => $sectionHeadingClass]) }}>{{ $title }}</h{{ $level }}>
@else
    <div {{ $attributes->merge(['class' => 'flex items-center justify-between gap-3']) }}>
        <h{{ $level }} class="{{ $sectionHeadingClass }}">{{ $title }}</h{{ $level }}>
        {{ $slot }}
    </div>
@endif
