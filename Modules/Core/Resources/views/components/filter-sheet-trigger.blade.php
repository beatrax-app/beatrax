@use('Modules\Core\Public\Support\Lang')
@props([
    'activeCount'  => 0,       // Number of active filter values (shows badge when > 0)
    'searchModel'  => null,    // wire:model target for the search input (optional)
])

{{--
    Filter sheet trigger row (UI-SPEC §6.4).
    Phone-only: search field + "Filters" button with active-count badge.
    The consuming Livewire component passes wire:click to open the bottom sheet.

    - Search input uses font-size: 16px to prevent iOS Safari auto-zoom.
    - .side-badge reuses the existing sidebar badge pattern.
    - $attributes forwards wire:click from the consumer to the Filters button.
--}}
<div class="filter-trigger-row" {{ $attributes->except(['wire:click', 'searchModel', 'activeCount']) }}>
    <div class="filter-search">
        <span class="ic" aria-hidden="true">⌕</span>
        <input
            type="search"
            placeholder="{{ Lang::get('core::components.search_placeholder') }}"
            aria-label="{{ Lang::get('core::components.search_aria') }}"
            @if ($searchModel) wire:model.live.debounce.300ms="{{ $searchModel }}" @endif
            style="font-size: 16px;"
        />
    </div>
    <button
        type="button"
        class="filter-trigger-btn"
        aria-label="{{ Lang::get('core::components.open_filters') }}{{ $activeCount > 0 ? Lang::get('core::components.filters_active', ['count' => $activeCount]) : '' }}"
        {{ $attributes->only('wire:click') }}
    >
        <span>{{ Lang::get('core::components.filters') }}</span>
        @if ($activeCount > 0)
            <span
                class="side-badge"
                aria-hidden="true"
            >{{ $activeCount }}</span>
        @endif
    </button>
</div>
