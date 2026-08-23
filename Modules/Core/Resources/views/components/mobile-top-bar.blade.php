@use('Modules\Core\Public\Support\Lang')
@props([
    'title'   => null,       // null = use config app.name
    'backUrl' => null,       // null = show hamburger; non-null = show ← back
])
@php
    $showBack = $backUrl !== null;
    $resolvedTitle = $title ?? config('app.name');
@endphp

{{--
    Mobile top bar (UI-SPEC §6.1).
    Visible only at <1024px (CSS .top-bar rule sets display:none at >=1024px).
    Default: hamburger + app name + palette button.
    Detail: back affordance + page title + palette button (set :backUrl to parent URL).
    44×44 tap targets on all buttons (WCAG 2.5.5).
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

    {{-- Mark and name as ONE unit. The title centres itself across the free
         space, so a logo left loose beside it stayed pinned against the
         hamburger and read as part of the menu button. --}}
    <span class="top-bar-brand">
        <x-core::app-mark :size="20" alt="" class="top-bar-logo" />

        <span class="top-bar-title">{{ $resolvedTitle }}</span>
    </span>

    {{-- One palette/search affordance, not two: this bar used to render a
         separate "palette" and "search" button that carried the same ⌕ glyph
         and dispatched the same event, which read as a duplicated icon. The
         palette IS the transaction search on phone, so the two
         collapse into this single control. --}}
    <button
        type="button"
        class="top-bar-btn ml-auto"
        aria-label="{{ Lang::get('core::components.topbar.search_transactions') }}"
        x-on:click="window.Livewire && window.Livewire.dispatch('palette:open')"
    >
        <x-core::search-mark />
    </button>
</header>
