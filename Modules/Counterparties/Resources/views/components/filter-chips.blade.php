{{--
    Filter chips — the type filter row on `/counterparties` index and
    `/counterparties/triage`. Renders an `All` chip plus one chip per
    type (merchant / personal / bank / government / self / unknown)
    with per-type colour dots and live counts.

    Group semantics: `role="group"` + `aria-label="Filter by type"`;
    each chip is a `<button>` with `aria-pressed` reflecting the
    active filter so screen readers announce the current selection.

    The wire:click bindings are emitted by the consuming Livewire page
    via attribute pass-through; this shell only carries the structure,
    aria, and class wiring.

    Props:
      active — string; the currently-active filter key (all|merchant|personal|bank|government|self|unknown)
      counts — array; per-type counts keyed by filter key (e.g. ['all' => 142, 'merchant' => 89, ...])
--}}
@props([
    'active' => 'all',
    'counts' => [],
])
@php
    $chips = [
        ['key' => 'all', 'label' => 'All', 'dot' => null],
        ['key' => 'merchant', 'label' => 'Merchants', 'dot' => 'dot-merchant'],
        ['key' => 'personal', 'label' => 'Personal', 'dot' => 'dot-personal'],
        ['key' => 'bank', 'label' => 'Banks', 'dot' => 'dot-bank'],
        ['key' => 'government', 'label' => 'Government', 'dot' => 'dot-gov'],
        ['key' => 'self', 'label' => 'Self', 'dot' => 'dot-self'],
        ['key' => 'unknown', 'label' => 'Unknown', 'dot' => 'dot-unknown'],
    ];
@endphp
<div
    {{ $attributes->merge(['class' => 'filter-chips']) }}
    role="group"
    aria-label="Filter by type"
>
    @foreach ($chips as $chip)
        @php
            $isActive = $active === $chip['key'];
            $count = $counts[$chip['key']] ?? 0;
        @endphp
        <button
            type="button"
            class="chip focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-slate-900"
            aria-pressed="{{ $isActive ? 'true' : 'false' }}"
            data-filter="{{ $chip['key'] }}"
        >
            @if ($chip['dot'] !== null)
                <span class="chip-dot {{ $chip['dot'] }}" aria-hidden="true"></span>
            @endif
            <span>{{ $chip['label'] }}</span>
            <span class="chip-count">{{ $count }}</span>
        </button>
    @endforeach
</div>
