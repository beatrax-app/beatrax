{{--
    PaletteSearchEndpoint — hidden server-state container.

    This component is a pure server-state holder. The palette JS client
    (resources/js/palette.js) calls $wire.search(q) on the mounted component
    to trigger the server-backed FTS query; state properties (transactionHits,
    entityHits, totalCount) become $wire-readable after the Livewire roundtrip.

    The actual rendering of palette hits lives in
    Modules/DevMode/Resources/views/livewire/command-palette-modal.blade.php —
    this view exists only to satisfy the Livewire component contract.

    The root element is `aria-hidden` and zero-height so it has no visual
    presence on any page it is mounted to.
--}}
<div aria-hidden="true" style="display:none;" data-testid="palette-search-endpoint"></div>
