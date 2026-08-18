@props([
    'name'  => '',         // Flux modal name for dispatch (used by consuming component)
    'title' => '',         // Sheet heading (optional)
])

{{--
    Bottom sheet wrapper (D-10, UI-SPEC §6.3, Pitfall 6).

    At >=768px: the .bottom-sheet CSS class is inactive (no override at desktop);
    the slot renders normally and the Flux modal inside it behaves as a standard
    centred dialog.

    At <768px: the .bottom-sheet class activates (via @media (max-width: 767px) in
    app.css), turning this into a fixed bottom panel that slides up from below.
    A scrim appears behind it; Escape and outside-tap both close it.

    Pitfall 6 mitigation: this component wraps the Livewire component that
    CONTAINS the flux:modal trigger — not the <flux:modal> element itself —
    so Flux's teleport to the top layer is never disrupted.

    Alpine's x-trap is deliberately absent. `x-trap.inert` on this overlay
    crashed the WebView renderer outright on Android: the tap registered, the
    renderer died, and every subsequent interaction did nothing while the page
    still looked normal. Verified by removing it — the same tap then opens the
    panel correctly. Escape and the scrim still dismiss, so the overlay is not
    a keyboard dead end, but focus is no longer trapped inside it; restoring a
    trap needs one that does not walk the document marking siblings inert.
    and released on close. safe-area padding-bottom keeps content above the home
    indicator on iOS.
--}}
<div
    x-data="{ open: false }"
    x-on:open-sheet.window="($event.detail && $event.detail.name === {{ Js::from($name) }}) && (open = true)"
    {{-- The mirror of open-sheet. Without it the sheet could only be dismissed
         by the scrim or Escape: a labelled Close button doing wire:click
         reached the server, reset the form, and left the panel sitting open,
         because its open state lives here in Alpine and nothing told it. --}}
    x-on:close-sheet.window="($event.detail && $event.detail.name === {{ Js::from($name) }}) && (open = false)"
    {{-- `modal-close` is the name the pages actually dispatch, and nothing in
         the codebase has ever dispatched `close-sheet`. So a save reached the
         server, wrote the row, closed the DESKTOP modal, and left the phone
         sheet sitting open over the list it had just added to. Both names are
         honoured rather than renaming one: the desktop modal answers to
         `modal-close` too, and one signal closing both surfaces is the point. --}}
    x-on:modal-close.window="($event.detail && $event.detail.name === {{ Js::from($name) }}) && (open = false)"
>
    {{-- Scrim: only rendered/shown at phone width (CSS .bottom-sheet-scrim display:none at desktop) --}}
    <div
        class="bottom-sheet-scrim"
        x-show="open"
        x-on:click="open = false"
        style="display: none;"
        aria-hidden="true"
    ></div>

    {{-- Bottom sheet panel.

         x-cloak AND the inline display:none, matching the scrim above. The
         panel carried neither, so between first paint and Alpine booting the
         whole sheet — heading, every field, both buttons — painted expanded
         over the page on every full load, for ≥50ms. The scrim did not paint
         with it, so it read as a form appearing and vanishing. The inline
         style is what holds before the stylesheet arrives; x-cloak is what
         holds if a transition leaves the inline value cleared. --}}
    <div
        class="bottom-sheet"
        x-cloak
        style="display: none;"
        x-show="open"
        x-transition:enter="transition ease-[var(--ease-smooth)] duration-[250ms]"
        x-transition:enter-start="translate-y-full"
        x-transition:enter-end="translate-y-0"
        x-transition:leave="transition ease-[var(--ease-smooth)] duration-[200ms]"
        x-transition:leave-start="translate-y-0"
        x-transition:leave-end="translate-y-full"
        @keydown.escape="open = false"
        role="dialog"
        aria-modal="true"
        @if ($title) aria-label="{{ $title }}" @endif
    >
        {{-- Drag handle (UI-SPEC §6.3) --}}
        <div
            style="width: 32px; height: 4px; background: var(--color-border-strong); border-radius: 2px; margin: 0 auto var(--space-3);"
            aria-hidden="true"
        ></div>

        @if ($title)
            <h2
                style="font-size: var(--text-base); font-weight: 600; color: var(--color-text); margin-bottom: var(--space-4);"
            >{{ $title }}</h2>
        @endif

        {{ $slot }}
    </div>
</div>
