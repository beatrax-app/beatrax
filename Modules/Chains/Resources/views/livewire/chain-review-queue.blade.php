{{-- /chains/review page (D-86 / D-87, CHN-03).

     Renders the user's open `state='candidate'` chain_links as a calm
     list of rows, sorted by confidence DESC then id DESC. Each row
     shows the from / to counterparties, the kind label ("PayPal
     funding" / "Bulk iDEAL settlement"), and Confirm + Reject buttons
     that delegate to the same Public action classes the chain drawer
     uses (D-86 dual-surface).

     Auto-promotion hint (D-87): the "One more confirm…" inline copy
     renders only on rows where `confirmsRemaining === 1` — the only
     proactive nudge about the learning loop.

     Each row uses Blade's default `{{ }}` escaping for all
     counterparty / amount / date interpolation. No `{!! !!}` raw-HTML
     anywhere.

     Copy is locked verbatim against 05-UI-SPEC.md § Copywriting
     Contract → "/chains/review page (D-86 / D-87)". --}}

@php
    use Modules\Ledger\Public\ValueObjects\Money;

    $fmt = static fn (Money $money): string => $money->currency() === 'EUR'
        ? $money->format('nl_NL')
        : $money->format('en_US');

    $kindLabel = static function (string $kind): string {
        if ($kind === 'paypal_funding') {
            return 'PayPal funding';
        }
        if ($kind === 'ics_bulk_settle') {
            return 'Bulk iDEAL settlement';
        }
        return $kind;
    };
@endphp

<div class="mx-auto max-w-5xl px-4 py-12">
    <header class="mb-12">
        <h1 class="text-2xl font-semibold tracking-tight text-slate-900">Review chains</h1>
        <p class="mt-2 text-sm text-slate-500">
            Confirm or reject candidate links the chain resolver could not auto-confirm.
        </p>
    </header>

    @if (count($candidates) === 0)
        <div class="rounded-lg border border-slate-200 bg-white p-6">
            <h2 class="text-base font-semibold text-slate-900">Nothing to review</h2>
            <p class="mt-2 text-sm text-slate-500">
                Every chain link is either confirmed or rejected. New candidates will appear here as imports land.
            </p>
        </div>
    @else
        <ul class="space-y-4">
            @foreach ($candidates as $row)
                <li class="rounded-lg border border-slate-200 bg-slate-50 p-4 opacity-90">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm text-slate-900">
                                <span>{{ $row->fromCounterparty }}</span>
                                <span class="text-slate-500" style="font-variant-numeric: tabular-nums;">{{ $fmt($row->fromAmount) }}</span>
                                <span aria-hidden="true" class="mx-1 text-slate-500">←</span>
                                <span>{{ $row->toCounterparty }}</span>
                                <span class="text-slate-500" style="font-variant-numeric: tabular-nums;">{{ $fmt($row->toAmount) }}</span>
                            </p>
                            <p class="mt-1 text-xs text-slate-500">
                                {{ $kindLabel($row->kind) }} · {{ $row->fromPostedAt->format('d M Y') }} → {{ $row->toPostedAt->format('d M Y') }}
                            </p>
                            @if ($row->confirmsRemaining === 1)
                                <p class="mt-1 flex items-center gap-1 text-xs text-emerald-700">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                    </svg>
                                    One more confirm and similar links auto-confirm.
                                </p>
                            @endif
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <button
                                type="button"
                                wire:click="confirm({{ $row->chainLinkId }})"
                                aria-label="Confirm chain link {{ $row->chainLinkId }}"
                                class="inline-flex items-center gap-1 rounded-md bg-emerald-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-emerald-700 focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2"
                            >Confirm</button>
                            <button
                                type="button"
                                wire:click="reject({{ $row->chainLinkId }})"
                                aria-label="Reject chain link {{ $row->chainLinkId }}"
                                class="inline-flex items-center gap-1 rounded-md bg-rose-50 px-2.5 py-1 text-xs font-medium text-rose-600 hover:bg-rose-100 focus-visible:ring-2 focus-visible:ring-rose-600 focus-visible:ring-offset-2"
                            >Reject</button>
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>

        @if (count($candidates) >= 25)
            @php
                $last = $candidates[count($candidates) - 1] ?? null;
            @endphp
            @if ($last !== null)
                <div class="mt-6 flex justify-center">
                    <button
                        type="button"
                        wire:click="loadMore({{ $last->chainLinkId }}, '{{ number_format($last->confidence, 3, '.', '') }}')"
                        class="inline-flex items-center rounded-md border border-slate-200 px-4 py-2 text-sm text-slate-900 hover:bg-slate-50 focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
                    >Show more</button>
                </div>
            @endif
        @endif
    @endif
</div>
