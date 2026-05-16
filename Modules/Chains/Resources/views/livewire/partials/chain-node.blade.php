{{-- Chain-node leg card (D-90 / D-91 / D-92 / D-93, UI-02).

     Issue #13 fix: explicit @props declaration. Both $node and
     $fanoutPage are required props — the parent passes them via
     `@include('chains::livewire.partials.chain-node', ['node' => ...,
     'fanoutPage' => ...])`. This makes the contract obvious at both
     ends of the call site and avoids the silent inheritance footgun
     where a child partial picks up a Livewire-component-scope
     property by accident. --}}
@props(['node', 'fanoutPage'])

@php
    use Modules\Ledger\Public\ValueObjects\Money;

    $fmt = static fn (Money $money): string => $money->currency() === 'EUR'
        ? $money->format('nl_NL')
        : $money->format('en_US');

    // D-91 confidence-tier mapping → chip text + chip chrome.
    // No hue encoding — UI-SPEC § Color forbids semantic-colour-by-
    // confidence. The Candidate parent card also carries `opacity-60`
    // for the calm "dimmed" treatment.
    $tierClasses = match ($node->confidenceTier) {
        'Deterministic' => 'bg-white text-slate-900 ring-1 ring-slate-200',
        'Confirmed'     => 'bg-slate-50 text-slate-900 ring-1 ring-slate-200',
        'Candidate'     => 'bg-slate-50 text-slate-500 ring-1 ring-slate-200',
        default         => 'bg-slate-50 text-slate-500 ring-1 ring-slate-200',
    };

    $tierAria = match ($node->confidenceTier) {
        'Deterministic' => 'Confidence: deterministic match',
        'Confirmed'     => 'Confidence: confirmed',
        'Candidate'     => 'Confidence: candidate; needs review',
        default         => 'Confidence: candidate; needs review',
    };

    $cardClasses = 'rounded-lg border border-slate-200 bg-white p-4 space-y-1';
    if ($node->confidenceTier === 'Candidate') {
        $cardClasses .= ' opacity-60';
    }

    $pageSize = 10;
    $visibleChildCount = $pageSize * ($fanoutPage + 1);
    $visibleChildren = array_slice($node->children, 0, $visibleChildCount);
    $childTotal = count($node->children);
    $remaining = $childTotal - count($visibleChildren);
    $nextChunk = min($pageSize, max(0, $remaining));
@endphp

<div class="{{ $cardClasses }}">
    <div class="flex items-center justify-between gap-md">
        <p class="text-sm text-slate-900">{{ $node->counterpartyName !== '' ? $node->counterpartyName : '—' }}</p>
        <div class="flex items-center gap-sm">
            <p class="text-sm text-slate-900" style="font-variant-numeric: tabular-nums;">
                {{ $fmt($node->amount) }}
            </p>
            <span
                aria-label="{{ $tierAria }}"
                class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium {{ $tierClasses }}"
            >{{ $node->confidenceTier }}</span>
        </div>
    </div>
    <p class="text-xs text-slate-500">
        {{ $node->bookedAt->format('d M Y') }} · {{ $node->accountName !== '' ? $node->accountName : '—' }}
    </p>

    @if ($node->confidenceTier === 'Candidate' && $node->chainLinkId !== null)
        <div class="mt-sm flex items-center gap-sm">
            <button
                type="button"
                wire:click="confirm({{ $node->chainLinkId }})"
                class="inline-flex items-center gap-1 rounded-md bg-emerald-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-emerald-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2"
                aria-label="Confirm chain link {{ $node->chainLinkId }}"
            >Confirm</button>
            <button
                type="button"
                wire:click="reject({{ $node->chainLinkId }})"
                class="inline-flex items-center gap-1 rounded-md bg-rose-50 px-2.5 py-1 text-xs font-medium text-rose-600 hover:bg-rose-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rose-600 focus-visible:ring-offset-2"
                aria-label="Reject chain link {{ $node->chainLinkId }}"
            >Reject</button>
        </div>
    @endif

    @if ($childTotal > 0)
        {{-- ICS bulk-settle fan-out (D-93). Renders the N covered ICS
             charges paginated at 10 per click. Pagination is forward-
             only — clicking the "Show 10 more · X of N" button
             increments the drawer's $fanoutPage cursor. --}}
        <div class="mt-md rounded-md border border-slate-200 bg-slate-50 p-3 space-y-2">
            <p class="text-xs text-slate-500">Covers {{ $childTotal }} ICS charges</p>
            @foreach ($visibleChildren as $child)
                <div class="flex items-center justify-between">
                    <p class="text-sm text-slate-900">{{ $child->counterpartyName !== '' ? $child->counterpartyName : '—' }}</p>
                    <p class="text-sm text-slate-900" style="font-variant-numeric: tabular-nums;">
                        {{ $fmt($child->amount) }}
                    </p>
                </div>
            @endforeach
            @if ($remaining > 0)
                <button
                    type="button"
                    wire:click="showMoreFanout"
                    class="mt-2 text-xs text-slate-500 hover:text-slate-900"
                >Show {{ $nextChunk }} more · {{ count($visibleChildren) }} of {{ $childTotal }}</button>
            @endif
        </div>
    @elseif ($node->kind === 'ics_bulk_settle')
        {{-- Empty fan-out edge case (D-93 discretion) — a refund-only
             month leaves a bulk-settle node covering zero ICS charges. --}}
        <div class="mt-md rounded-md border border-slate-200 bg-slate-50 p-3">
            <p class="text-xs text-slate-500">No ICS charges in this settlement</p>
        </div>
    @endif
</div>
