{{--
    The Stage-B shell: real SwiftUI / Jetpack Compose chrome around the
    existing Livewire body. Every destination below is a route that already
    exists — nothing here re-implements a screen, it only replaces the
    web-rendered chrome (drawer, top bar, safe-area padding) with the
    platform's own.

    `php` on the webview is what makes this the app's own Laravel runtime
    rather than a sandboxed foreign page: shared session and the asset
    pipeline. The `javascript` / `dom-storage` opt-ins are deliberately
    absent — the renderers force both on in php mode, and passing them would
    imply they were optional here.

    Not the `window.Native` JS bridge, though: its calls POST to
    /_native/api/call, and NativeBridgeIsShellOnly answers 404 there. Native
    results reach this app as `native-event` CustomEvents instead.

    No `safe-area` class: the screen carries chrome on both edges, and
    adding it on top double-pads.
--}}
<column class="w-full h-full">
    {{-- A bare <top-bar /> is dropped: an empty chrome element contributes
         nothing to hoist, so the bar never reaches the native shell and
         navTitle() has nowhere to land. The title is what makes it real. --}}
    <top-bar title="{{ $this->navTitle() }}" />

    <webview php src="{{ $path }}" @navigated="onNavigated" class="flex-1 w-full" />

    <bottom-nav>
        {{-- `active`, not `selected`: BottomNavItem only reads a fixed key
             list, and anything outside it is dropped in silence. The icons
             go on the item for the same reason — a nested <icon> renders,
             but the bar wants its own per-platform glyph. --}}
        @foreach ($destinations as $key => $destination)
            <bottom-nav-item
                label="{{ $destination['label'] }}"
                ios="{{ $destination['ios'] }}"
                android="{{ $destination['android'] }}"
                :active="$active === $key"
                @press="open('{{ $key }}')"
            />
        @endforeach
    </bottom-nav>
</column>
