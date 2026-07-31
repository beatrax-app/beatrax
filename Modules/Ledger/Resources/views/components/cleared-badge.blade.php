@php
    /**
     * @var array<string,mixed> $transaction  Row array with keys: id, status
     *      (one of Modules\Ledger\Models\Transaction::STATUSES).
     *
     * Status -> visual mapping (SC-1, D-01/D-11, component-library.md):
     *   cleared    -> .status-pill.ok    (emerald), interactive toggle
     *   reconciled -> .status-pill.ok    (emerald) + lock glyph, NON-interactive
     *                 (D-08 — editing a reconciled row requires an explicit
     *                 un-reconcile first; the badge itself never mutates it)
     *   uncleared  -> .status-pill.muted (slate), interactive toggle
     *
     * Interactive badges dispatch the `cleared-toggle` Livewire event with
     * the row id — handled by HandlesClearedStatus::toggleClearedRow(),
     * mixed into both TransactionsList and TransactionDetail (mirrors
     * x-tax::tax-badge's `$dispatch('tax-tag', ...)` pattern so this
     * component works unmodified from either surface).
     */
    $status = is_string($transaction['status'] ?? null) ? $transaction['status'] : \Modules\Ledger\Public\Enums\ClearedStatus::Cleared->value;
    $txId = (int) ($transaction['id'] ?? 0);
    $isReconciled = $status === \Modules\Ledger\Public\Enums\ClearedStatus::Reconciled->value;

    $variant = $status === \Modules\Ledger\Public\Enums\ClearedStatus::Uncleared->value ? 'muted' : 'ok';
    $label = match ($status) {
        'reconciled' => 'Reconciled',
        'uncleared' => 'Uncleared',
        default => 'Cleared',
    };
@endphp

@if ($isReconciled)
    {{-- Reconciled: locked, non-interactive — un-reconcile happens on the
         /reconcile workflow (13.3-04), never via this badge. --}}
    <span
        class="status-pill ok"
        title="Reconciled — un-reconcile first to change status."
        data-testid="cleared-badge-reconciled-{{ $txId }}"
    >
        <span class="dot"></span>
        <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zM7 11V7a5 5 0 0110 0v4" />
        </svg>
        {{ $label }}
    </span>
@else
    <button
        type="button"
        wire:click="$dispatch('cleared-toggle', { id: {{ $txId }} })"
        class="status-pill {{ $variant }} cleared-badge-toggle"
        aria-label="{{ $label }} — click to toggle"
        data-testid="cleared-badge-{{ $txId }}"
    >
        <span class="dot"></span>
        {{ $label }}
    </button>
@endif
