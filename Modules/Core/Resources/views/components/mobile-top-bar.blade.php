@props([
    'title'   => null,       // null = use config app.name
    'backUrl' => null,       // null = show hamburger; non-null = show ← back (D-05)
])
@php
    $showBack = $backUrl !== null;
    $resolvedTitle = $title ?? config('app.name');
@endphp

{{--
    Mobile top bar (D-01/D-02/D-05/D-14, UI-SPEC §6.1).
    Visible only at <1024px (CSS .top-bar rule sets display:none at >=1024px).
    Default: hamburger + app name + palette button.
    Detail: back affordance + page title + palette button (set :backUrl to parent URL).
    44×44 tap targets on all buttons (D-14 / WCAG 2.5.5).
--}}
<header class="top-bar" aria-label="Mobile navigation">
    {{-- Hamburger or back affordance --}}
    @if ($showBack)
        <a
            href="{{ $backUrl }}"
            class="top-bar-btn"
            aria-label="Back"
            wire:navigate
        >
            <span aria-hidden="true">←</span>
        </a>
    @else
        <button
            type="button"
            class="top-bar-btn"
            aria-label="Open navigation"
            :aria-expanded="$store.mobileNav.drawerOpen.toString()"
            x-on:click="$store.mobileNav.toggle()"
        >
            <span aria-hidden="true">☰</span>
        </button>
    @endif

    <span class="top-bar-title">{{ $resolvedTitle }}</span>

    {{-- Palette button (D-02/D-13) — ⌕ glyph only; kbd hint hidden on touch per CSS --}}
    <button
        type="button"
        class="top-bar-btn ml-auto"
        aria-label="Open command palette"
        x-on:click="window.Livewire && window.Livewire.dispatch('palette:open')"
    >
        <span aria-hidden="true">⌕</span>
    </button>
</header>
