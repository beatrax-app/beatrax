@use('Modules\Core\Public\Support\Lang')
@use('Modules\Core\Public\Services\UserDataPathService')
@php
    /**
     * @var array<string,mixed> $transaction  Row array with keys: id, status
     *      (one of Modules\Ledger\Models\Transaction::STATUSES).
     *
     * Status -> visual mapping (component-library.md):
     *   cleared    -> .status-pill.ok    (emerald), interactive toggle
     *   reconciled -> .status-pill.ok    (emerald) + lock glyph, NON-interactive
     *                 (editing a reconciled row requires an explicit
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
        \Modules\Ledger\Public\Enums\ClearedStatus::Reconciled->value => Lang::get('ledger::common.status.reconciled'),
        \Modules\Ledger\Public\Enums\ClearedStatus::Uncleared->value => Lang::get('ledger::common.status.uncleared'),
        default => Lang::get('ledger::common.status.cleared'),
    };
@endphp

@if ($isReconciled)
    {{-- Reconciled: locked, non-interactive. The way out is the
         un-reconcile control on the transaction detail page, never this
         badge — the same badge draws on every row of the list, where one
         mis-tap would unlock a transaction the reader meant only to read. --}}
    <span
        class="status-pill ok"
        title="{{ Lang::get('ledger::common.badge.reconciled_hint') }}"
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
        wire:click="$dispatch('cleared-toggle', { id: '{{ $txId }}' })"
        class="status-pill {{ $variant }} cleared-badge-toggle"
        aria-label="{{ UserDataPathService::isMobileRuntime() ? Lang::get('ledger::common.badge.toggle_aria_touch', ['label' => $label]) : Lang::get('ledger::common.badge.toggle_aria', ['label' => $label]) }}"
        data-testid="cleared-badge-{{ $txId }}"
    >
        <span class="dot"></span>
        {{ $label }}
    </button>
@endif
