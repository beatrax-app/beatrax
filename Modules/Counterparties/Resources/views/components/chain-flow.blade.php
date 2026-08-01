@use('Modules\Core\Public\Support\Lang')
{{--
    Chain flow — compact funding-chain visualization shown in the
    merchant-profile Overview "Funding chain" summary. Renders a row
    of `chain-node` boxes separated by `chain-arrow` glyphs so the
    routing path (e.g. PayPal → ASN → Merchant) reads as a single
    horizontal line.

    Each node carries an optional source-glyph (asn / ics / paypal /
    merchant / bank) that maps to the per-source `.node-glyph.*`
    background tokens declared in app.css.

    Aria-label spells out the chain end-to-end so screen readers carry
    the routing path without needing to walk every node.

    Props:
      nodes — array of nodes; each node is { label, glyph } where glyph
              is one of asn|ics|paypal|merchant|bank (or null)
--}}
@props([
    'nodes' => [],
])
@php
    $labels = array_map(static fn ($node) => is_array($node) ? ($node['label'] ?? '') : (string) $node, $nodes);
    $ariaLabel = Lang::get('counterparties::components.chain_flow.aria_prefix').implode(Lang::get('counterparties::components.chain_flow.join'), array_filter($labels));
@endphp
<div
    {{ $attributes->merge(['class' => 'chain-flow']) }}
    role="group"
    aria-label="{{ $ariaLabel }}"
>
    @foreach ($nodes as $index => $node)
        @php
            $label = is_array($node) ? ($node['label'] ?? '') : (string) $node;
            $glyph = is_array($node) ? ($node['glyph'] ?? null) : null;
        @endphp
        <span class="chain-node">
            @if ($glyph !== null)
                <span class="node-glyph {{ $glyph }}" aria-hidden="true">{{ strtoupper(substr($glyph, 0, 1)) }}</span>
            @endif
            <span>{{ $label }}</span>
        </span>
        @if ($index < count($nodes) - 1)
            <span class="chain-arrow" aria-hidden="true">→</span>
        @endif
    @endforeach
</div>
