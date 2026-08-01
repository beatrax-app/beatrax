@use('Modules\Core\Public\Support\Lang')
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
<header {{ $attributes->merge(['class' => 'top-bar']) }} aria-label="{{ Lang::get('core::components.topbar.mobile_nav') }}">
    {{-- Hamburger or back affordance --}}
    @if ($showBack)
        <a
            href="{{ $backUrl }}"
            class="top-bar-btn"
            aria-label="{{ Lang::get('core::components.topbar.back') }}"
            wire:navigate
        >
            <span aria-hidden="true">←</span>
        </a>
    @else
        <button
            type="button"
            class="top-bar-btn"
            aria-label="{{ Lang::get('core::components.topbar.open_nav') }}"
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
        aria-label="{{ Lang::get('core::components.topbar.open_palette') }}"
        x-on:click="window.Livewire && window.Livewire.dispatch('palette:open')"
    >
        <span aria-hidden="true">⌕</span>
    </button>

    {{-- Search magnifier button (D-25, UI-SPEC #9, Phase 8 Plan 05).
         Opens the palette in its full-screen phone mode (D-29).
         44px tap target (D-08 / WCAG 2.5.5). aria-label required for icon-only button. --}}
    <button
        type="button"
        class="top-bar-btn"
        aria-label="{{ Lang::get('core::components.topbar.search_transactions') }}"
        x-on:click="window.Livewire && window.Livewire.dispatch('palette:open')"
        style="width: 44px; height: var(--top-bar-h, 56px); display: grid; place-items: center; color: var(--color-text-muted, #64748b);"
    >
        <span aria-hidden="true">⌕</span>
    </button>
</header>
