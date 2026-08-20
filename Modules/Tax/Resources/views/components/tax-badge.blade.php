@use('Modules\Core\Public\Support\Lang')
@php
    /**
     * @var array<string,mixed> $transaction  Row array with keys: id, taxTagged, taxCategoryShortName
     * @var bool $showAlways  true = always visible (touch); false = hover-reveal (desktop)
     * @var bool $readonly  Phase 13.1 Plan 06 — true renders a non-interactive badge (no
     *           click-to-tag/-edit dispatch). Used for split-leg sub-rows on list surfaces:
     *           legs are read-only there (editing only happens in TransactionDetail's split
     *           editor) and this component only knows how to dispatch a WHOLE-transaction
     *           tag/edit event keyed by `id` — a clickable leg badge would silently mistag the
     *           parent transaction instead of the leg. Untagged legs render nothing (no ghost
     *           "Tag" CTA — read-only surfaces never offer an action that can't be fulfilled).
     */
    $tagged            = (bool) ($transaction['taxTagged'] ?? false);
    $categoryShortName = is_string($transaction['taxCategoryShortName'] ?? null) ? $transaction['taxCategoryShortName'] : null;
    $txId              = (int) ($transaction['id'] ?? 0);
    $label             = $categoryShortName ?? Lang::get('tax::badge.default_label');
    $readonly          ??= false;
@endphp

@if ($readonly)
    @if ($tagged)
        <span class="tax-badge inline-flex items-center" data-testid="tax-badge-readonly-{{ $txId }}">{{ $label }}</span>
    @endif
@elseif ($tagged)
    {{-- Tagged: emerald filled pill. Clicking re-opens the picker to edit. --}}
    <button
        type="button"
        wire:click="$dispatch('tax-edit-tag', { id: {{ $txId }} })"
        class="tax-badge inline-flex items-center"
        aria-label="{{ Lang::get('tax::badge.edit_aria', ['label' => $label]) }}"
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
        aria-label="{{ Lang::get('tax::badge.tag_aria') }}"
        title="{{ Lang::get('tax::badge.tag') }}"
        data-testid="tax-badge-untagged-{{ $txId }}"
    {{-- A label per row costs more width than a transaction row has on a
         phone. The word stays for screen readers and as the tooltip, and
         returns at sm: where there is room for it. --}}
    ><span aria-hidden="true" class="sm:hidden">🏷</span><span class="sr-only sm:not-sr-only">{{ Lang::get('tax::badge.tag') }}</span></button>
@endif
