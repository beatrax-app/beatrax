{{-- Chain-node leg card.

     Explicit @props declaration. Both $node and
     $fanoutPage are required props — the parent passes them via
     `@include('chains::livewire.partials.chain-node', ['node' => ...,
     'fanoutPage' => ...])`. This makes the contract obvious at both
     ends of the call site and avoids the silent inheritance footgun
     where a child partial picks up a Livewire-component-scope
     property by accident. --}}
@use('Modules\Core\Public\Support\Lang')
@props(['node', 'fanoutPage'])

@php
    use Modules\Ledger\Public\ValueObjects\Money;

    $fmt = static fn (Money $money): string => $money->format();

    // No hue encoding: confidence is signalled by surface and text weight
    // only, never by a semantic colour.
    $tierClasses = match ($node->confidenceTier) {
        'Deterministic' => 'bg-white text-slate-900 ring-1 ring-slate-200 dark:bg-slate-950 dark:text-slate-100 dark:ring-slate-700',
        'Confirmed'     => 'bg-slate-50 text-slate-900 ring-1 ring-slate-200 dark:bg-slate-900 dark:text-slate-100 dark:ring-slate-700',
        'Candidate'     => 'bg-slate-50 text-slate-500 ring-1 ring-slate-200 dark:bg-slate-900 dark:text-slate-400 dark:ring-slate-700',
        default         => 'bg-slate-50 text-slate-500 ring-1 ring-slate-200 dark:bg-slate-900 dark:text-slate-400 dark:ring-slate-700',
    };

    $tierAria = match ($node->confidenceTier) {
        'Deterministic' => Lang::get('chains::drawer.confidence_aria.deterministic'),
        'Confirmed'     => Lang::get('chains::drawer.confidence_aria.confirmed'),
        'Candidate'     => Lang::get('chains::drawer.confidence_aria.candidate'),
        default         => Lang::get('chains::drawer.confidence_aria.candidate'),
    };

    $cardClasses = 'rounded-lg border border-slate-200 bg-white p-4 space-y-1 dark:bg-slate-950 dark:border-slate-700';
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
        {{-- Counterparty name on each chain leg links to its profile
             when the underlying transaction has been resolved by the
             CounterpartyResolver chain; plain text otherwise so legs
             without a resolved counterparty still render cleanly. --}}
        <p class="text-sm text-slate-900 dark:text-slate-100">
            @if ($node->counterpartyName !== '' && $node->counterpartySlug !== null)
                <a
                    href="{{ route('counterparties.profile', ['slug' => $node->counterpartySlug]) }}"
                    wire:navigate
                    class="underline-offset-2 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:focus-visible:ring-slate-100"
                    data-testid="chain-node-counterparty-link-{{ $node->transactionId }}"
                >{{ $node->counterpartyName }}</a>
            @else
                <span data-testid="chain-node-counterparty-text-{{ $node->transactionId }}">{{ $node->counterpartyName !== '' ? $node->counterpartyName : '—' }}</span>
            @endif
        </p>
        <div class="flex items-center gap-sm">
            <p class="text-sm text-slate-900 dark:text-slate-100" style="font-variant-numeric: tabular-nums;">
                {{ $fmt($node->amount) }}
            </p>
            <span
                role="img"
                aria-label="{{ $tierAria }}"
                class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium {{ $tierClasses }}"
            >{{ $node->confidenceTier }}</span>
        </div>
    </div>
    <p class="text-xs text-slate-500 dark:text-slate-400">
        {{ $node->bookedAt->translatedFormat('d M Y') }} · {{ $node->accountName !== '' ? $node->accountName : '—' }}
    </p>

    @if ($node->confidenceTier === 'Candidate' && $node->chainLinkId !== null)
        <div class="mt-sm flex items-center gap-sm">
            <button
                type="button"
                wire:click="confirm({{ $node->chainLinkId }})"
                class="inline-flex items-center gap-1 rounded-md bg-emerald-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-emerald-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2 dark:bg-emerald-500 dark:hover:bg-emerald-400"
                aria-label="{{ Lang::get('chains::drawer.confirm_aria', ['id' => $node->chainLinkId]) }}"
            >{{ Lang::get('chains::drawer.confirm') }}</button>
            <button
                type="button"
                wire:click="reject({{ $node->chainLinkId }})"
                class="inline-flex items-center gap-1 rounded-md bg-rose-50 px-2.5 py-1 text-xs font-medium text-rose-600 hover:bg-rose-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rose-600 focus-visible:ring-offset-2 dark:bg-rose-950 dark:text-rose-500 dark:hover:bg-rose-900"
                aria-label="{{ Lang::get('chains::drawer.reject_aria', ['id' => $node->chainLinkId]) }}"
            >{{ Lang::get('chains::drawer.reject') }}</button>
        </div>
    @endif

    @if ($childTotal > 0)
        {{-- ICS bulk-settle fan-out. Renders the N covered ICS
             charges paginated at 10 per click. Pagination is forward-
             only — clicking the "Show 10 more · X of N" button
             increments the drawer's $fanoutPage cursor. --}}
        <div class="mt-md rounded-md border border-slate-200 bg-slate-50 p-3 space-y-2 dark:bg-slate-900 dark:border-slate-700">
            <p class="text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('chains::drawer.covers_charges', ['count' => $childTotal]) }}</p>
            @foreach ($visibleChildren as $child)
                <div class="flex items-center justify-between">
                    <p class="text-sm text-slate-900 dark:text-slate-100">
                        @if ($child->counterpartyName !== '' && $child->counterpartySlug !== null)
                            <a
                                href="{{ route('counterparties.profile', ['slug' => $child->counterpartySlug]) }}"
                                wire:navigate
                                class="underline-offset-2 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:focus-visible:ring-slate-100"
                                data-testid="chain-node-child-counterparty-link-{{ $child->transactionId }}"
                            >{{ $child->counterpartyName }}</a>
                        @else
                            <span data-testid="chain-node-child-counterparty-text-{{ $child->transactionId }}">{{ $child->counterpartyName !== '' ? $child->counterpartyName : '—' }}</span>
                        @endif
                    </p>
                    <p class="text-sm text-slate-900 dark:text-slate-100" style="font-variant-numeric: tabular-nums;">
                        {{ $fmt($child->amount) }}
                    </p>
                </div>
            @endforeach
            @if ($remaining > 0)
                <button
                    type="button"
                    wire:click="showMoreFanout"
                    class="mt-2 text-xs text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-100"
                >{{ Lang::get('chains::drawer.show_more_fanout', ['count' => $nextChunk, 'shown' => count($visibleChildren), 'total' => $childTotal]) }}</button>
            @endif
        </div>
    @elseif ($node->kind === \Modules\Chains\Public\Enums\ChainLinkKind::IcsBulkSettle->value)
        {{-- Empty fan-out edge case — a refund-only
             month leaves a bulk-settle node covering zero ICS charges. --}}
        <div class="mt-md rounded-md border border-slate-200 bg-slate-50 p-3 dark:bg-slate-900 dark:border-slate-700">
            <p class="text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('chains::drawer.no_ics_charges') }}</p>
        </div>
    @endif
</div>
