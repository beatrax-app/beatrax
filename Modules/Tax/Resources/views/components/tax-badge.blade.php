@php
    /**
     * @var array<string,mixed> $transaction  Row array with keys: id, taxTagged, taxCategoryShortName
     * @var bool $showAlways  true = always visible (touch); false = hover-reveal (desktop)
     */
    $tagged            = (bool) ($transaction['taxTagged'] ?? false);
    $categoryShortName = is_string($transaction['taxCategoryShortName'] ?? null) ? $transaction['taxCategoryShortName'] : null;
    $txId              = (int) ($transaction['id'] ?? 0);
    $label             = $categoryShortName ?? 'Tax';
@endphp

@if ($tagged)
    {{-- Tagged: emerald filled pill. Clicking re-opens the picker to edit. --}}
    <button
        type="button"
        wire:click="$dispatch('tax-edit-tag', { id: {{ $txId }} })"
        class="tax-badge inline-flex items-center"
        aria-label="Edit tax tag: {{ $label }}"
        data-testid="tax-badge-tagged-{{ $txId }}"
    >{{ $label }}</button>
@else
    {{-- Untagged: ghost "Tag" button.
         On desktop: only visible on group-hover / focus-within (.row-cta pattern).
         On touch ($showAlways=true): always visible at ≥44px tap target. --}}
    <button
        type="button"
        wire:click="$dispatch('tax-tag', { id: {{ $txId }} })"
        @class([
            'tax-badge--untagged inline-flex items-center',
            'opacity-0 group-hover:opacity-100 focus:opacity-100' => ! $showAlways,
            'always-show-touch' => $showAlways,
        ])
        aria-label="Tag as tax-relevant"
        data-testid="tax-badge-untagged-{{ $txId }}"
    >Tag</button>
@endif
