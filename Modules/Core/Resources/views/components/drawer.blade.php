@use('Modules\Core\Public\Support\Lang')
{{--
    Slide-over drawer (D-01/D-03, UI-SPEC §6.2, Pitfall 1).

    The drawer wraps the SINGLE @livewire('core.app-sidebar') mount for the
    entire app. At >=1024px (desktop) the .drawer-container is position:static;
    transform:none (CSS override) — it behaves as the normal static sidebar.
    At <1024px (phone/tablet) it slides in from the left when the hamburger is
    tapped; the scrim closes it on outside tap; Escape also closes.

    Pitfall 1 mitigation: exactly ONE sidebar mount lives here. The outer layout
    must NOT contain a second @livewire('core.app-sidebar') call.

    T-04-03-01: one mount only (verified by the acceptance test grep -c).
    Alpine's x-trap is deliberately absent. `x-trap.inert` on this overlay
    crashed the WebView renderer outright on Android: the tap registered, the
    renderer died, and every subsequent interaction did nothing while the page
    still looked normal. Verified by removing it — the same tap then opens the
    panel correctly. Escape and the scrim still dismiss, so the overlay is not
    a keyboard dead end, but focus is no longer trapped inside it; restoring a
    trap needs one that does not walk the document marking siblings inert.

    D-05 (Phase 15 Plan 10): the Data & Devices entry
    (route('data-devices.index')) lives INSIDE the embedded core.app-sidebar
    component below, not as separate markup here — this drawer has exactly one
    content source (Pitfall 1 above), so any nav row or section added to
    app-sidebar.blade.php automatically surfaces in both the desktop static
    sidebar and this phone/tablet slide-over drawer without touching this
    file's structure.
--}}

{{-- Scrim (phone/tablet only — CSS hides it at desktop via .drawer-scrim rule) --}}
<div
    class="drawer-scrim"
    x-show="$store.mobileNav.drawerOpen"
    x-on:click="$store.mobileNav.close()"
    x-transition:enter="transition duration-[180ms]"
    x-transition:enter-start="opacity-0"
    x-transition:enter-end="opacity-100"
    x-transition:leave="transition duration-[180ms]"
    x-transition:leave-start="opacity-100"
    x-transition:leave-end="opacity-0"
    style="background: rgba(15,23,42,0.4); position: fixed; inset: 0; z-index: 40;"
    aria-hidden="true"
></div>

{{-- Drawer panel --}}
<div
    class="drawer-container"
    role="dialog"
    aria-modal="true"
    aria-label="{{ Lang::get('core::components.drawer_nav') }}"
    x-show="$store.mobileNav.drawerOpen"
    x-transition:enter="transition ease-[var(--ease-smooth)] duration-[220ms]"
    x-transition:enter-start="-translate-x-full"
    x-transition:enter-end="translate-x-0"
    x-transition:leave="transition ease-[var(--ease-smooth)] duration-[220ms]"
    x-transition:leave-start="translate-x-0"
    x-transition:leave-end="-translate-x-full"
    @keydown.escape="$store.mobileNav.close()"
    style="width: var(--drawer-w); position: fixed; top: 0; left: 0; height: 100dvh; z-index: 50;"
>
    {{-- Single mount of the sidebar Livewire component (Pitfall 1 — exactly one) --}}
    @livewire('core.app-sidebar')
</div>
