@props([
    'size' => 48,            // Edge in px, written to width AND height. false leaves both off for a mark sized by class alone.
    'alt' => 'Beatrax',      // Empty where a visible wordmark already names it.
    'decorative' => true,    // aria-hidden. False where nothing else beside the mark names it.
    'class' => 'rounded-xl', // Replaces the default rather than merging with it — see below.
])

{{--
    The product mark, drawn from the one brand asset.

    Nine of these existed and every one wrote out Vite::asset(...) again,
    so the asset path was nine literals and a rename would have had to find
    all nine. Four of them — the desktop lock screen, the phone lock
    screen, the setup-progress screen and the sync-complete screen — were
    the same ten lines to the character, 48px and rounded-xl and all. Those
    four are what the defaults here are.

    The other five differ in ways the defaults cannot absorb, so each
    difference is a prop: 20px in the phone top bar, 22px in the setup
    wizard, 24px in the sidebar, 48px dimmed behind the lock veil, and a
    welcome screen that sizes the mark by class with no width or height at
    all. That last one passes :size="false" rather than null, because
    @props resolves a null-valued attribute back to its own default and the
    mark would come out 48px.

    The attributes are merged from one array rather than written as tag
    lines around @if directives, because the directives leave their own
    indentation in the output: the sidebar's mark is under a snapshot lock
    (tests/Snapshot/SidebarTest.php) that holds the whole element to one
    line, and alt-before-width is the order that lock already records.

    alt is the one exception, written on the tag. An alt that only exists
    after the merge runs is invisible to a reader of the template and to
    the HTML analyser, which reported this img as having none. Placing it
    first keeps the order the lock records.

    `class` is a prop rather than something a caller merges in, for the
    reason x-core::progress-bar gives about its own width: two Tailwind
    classes for the same property on one element do not resolve by class
    order, so a caller asking for h-20 w-20 against a default rounded-xl
    would be adding to it, not replacing it. Naming it replaces it.

    `alt` and `decorative` answer different questions and so are separate.
    Beside a visible "Beatrax" wordmark the mark is redundant and takes
    alt=""; on a lock screen it is the only brand cue and keeps its name.
    aria-hidden is what stops a reader announcing the mark and the wordmark
    as two things.
--}}
@php
    $markAttributes = [];

    if ($size !== false) {
        $markAttributes['width'] = (string) $size;
        $markAttributes['height'] = (string) $size;
    }

    $markAttributes['class'] = $class;

    if ($decorative) {
        $markAttributes['aria-hidden'] = 'true';
    }
@endphp
<img src="{{ Vite::asset('resources/brand/logo.svg') }}" alt="{{ $alt }}" {{ $attributes->merge($markAttributes) }} />
