@props([
    'title',   // Required. The section's name — an h2, so a page's h1 stays the page's own.
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
--}}
@if ($slot->isEmpty())
    <h2 {{ $attributes->merge(['class' => 'text-base font-semibold text-slate-900 dark:text-slate-100']) }}>{{ $title }}</h2>
@else
    <div {{ $attributes->merge(['class' => 'flex items-center justify-between gap-3']) }}>
        <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ $title }}</h2>
        {{ $slot }}
    </div>
@endif
