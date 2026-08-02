@use('Modules\Core\Public\Support\Lang')
{{-- /chains — overview of every non-rejected chain_link for the user.
     One card per chain showing both legs side-by-side, the chain kind,
     a confidence chip, and a drill-in link to the funded transaction's
     detail page. The detail page surfaces the chain drawer for full
     fan-out exploration. --}}
@php
    $kindLabel = static function (string $kind): string {
        return match ($kind) {
            'paypal_funding' => Lang::get('chains::index.kind.paypal_funding'),
            'ics_bulk_settle' => Lang::get('chains::index.kind.ics_bulk_settle'),
            'funded_by_card_hint' => Lang::get('chains::index.kind.funded_by_card_hint'),
            'refund_of_hint' => Lang::get('chains::index.kind.refund_of_hint'),
            default => $kind,
        };
    };
    $fmt = static fn ($money) => $money->format($money->currency() === 'EUR' ? 'nl_NL' : 'en_US');
@endphp

<div class="mx-auto max-w-5xl px-4 py-12">
    <header class="mb-8">
        <div class="flex items-baseline justify-between gap-4">
            <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">{{ Lang::get('chains::index.heading') }}</h1>
            <div class="flex items-center gap-3 text-xs text-slate-500 dark:text-slate-400">
                <a
                    href="{{ route('chains.review') }}"
                    class="hover:text-slate-700 dark:hover:text-slate-200"
                    data-testid="chains-index-review-link"
                >{{ Lang::get('chains::index.review_link') }}</a>
                <a
                    href="{{ route('chains.hints') }}"
                    class="hover:text-slate-700 dark:hover:text-slate-200"
                    data-testid="chains-index-hints-link"
                >{{ Lang::get('chains::index.hints_link') }}</a>
            </div>
        </div>
        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
            {{ Lang::get('chains::index.subtitle') }}
        </p>
    </header>

    @if (count($chains) === 0)
        <div class="rounded-lg border border-slate-200 bg-white p-6 dark:bg-slate-950 dark:border-slate-700" data-testid="chains-index-empty">
            <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('chains::index.empty_heading') }}</h2>
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                {{ Lang::get('chains::index.empty_body') }}
            </p>
        </div>
    @else
        {{-- overflow-x: auto wrapper so dense chain rows scroll horizontally at phone width (D-06 power split) --}}
        <div class="overflow-x-scroll-wrapper" style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
        <ul class="space-y-3" data-testid="chains-index-list" style="min-width: 480px;">
            @foreach ($chains as $chain)
                @php
                    $tierClasses = match ($chain->state) {
                        'confirmed' => 'bg-slate-50 text-slate-900 ring-1 ring-slate-200 dark:bg-slate-900 dark:text-slate-100 dark:ring-slate-700',
                        'candidate' => 'bg-amber-50 text-amber-800 ring-1 ring-amber-200 dark:bg-amber-950 dark:text-amber-200 dark:ring-amber-800',
                        default => 'bg-slate-50 text-slate-500 ring-1 ring-slate-200 dark:bg-slate-900 dark:text-slate-400 dark:ring-slate-700',
                    };
                @endphp
                <li
                    class="rounded-lg border border-slate-200 bg-white p-4 dark:bg-slate-950 dark:border-slate-700"
                    data-testid="chain-row-{{ $chain->chainLinkId }}"
                >
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0 flex-1">
                            <p class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">
                                {{ $kindLabel($chain->kind) }}
                            </p>
                            {{-- Each leg's counterparty NAME links to
                                 the counterparty profile (D-46). The
                                 transaction-detail drill-in is still
                                 reachable from the date row beneath
                                 (and from the chain drawer triggered
                                 on the detail page). When a leg lacks
                                 a resolved counterparty slug, the name
                                 renders as plain text instead of a
                                 dead-end link. --}}
                            <p class="mt-1 text-sm text-slate-900 dark:text-slate-100">
                                @if ($chain->fromCounterpartySlug !== null)
                                    <a
                                        href="{{ route('counterparties.profile', ['slug' => $chain->fromCounterpartySlug]) }}"
                                        wire:navigate
                                        class="underline-offset-2 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:focus-visible:ring-slate-100"
                                        data-testid="chains-index-from-counterparty-link-{{ $chain->chainLinkId }}"
                                    >{{ $chain->fromCounterparty ?: Lang::get('chains::index.no_counterparty') }}</a>
                                @else
                                    <span data-testid="chains-index-from-counterparty-text-{{ $chain->chainLinkId }}">{{ $chain->fromCounterparty ?: Lang::get('chains::index.no_counterparty') }}</span>
                                @endif
                                <span
                                    class="ml-2 text-slate-500 dark:text-slate-400"
                                    style="font-variant-numeric: tabular-nums;"
                                >{{ $fmt($chain->fromAmount) }}</span>
                                <span aria-hidden="true" class="mx-2 text-slate-400 dark:text-slate-500">←</span>
                                @if ($chain->toCounterpartySlug !== null)
                                    <a
                                        href="{{ route('counterparties.profile', ['slug' => $chain->toCounterpartySlug]) }}"
                                        wire:navigate
                                        class="text-slate-900 underline-offset-2 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:text-slate-100 dark:focus-visible:ring-slate-100"
                                        data-testid="chains-index-to-counterparty-link-{{ $chain->chainLinkId }}"
                                    >{{ $chain->toCounterparty ?: Lang::get('chains::index.no_counterparty') }}</a>
                                @else
                                    <span data-testid="chains-index-to-counterparty-text-{{ $chain->chainLinkId }}">{{ $chain->toCounterparty ?: Lang::get('chains::index.no_counterparty') }}</span>
                                @endif
                                <span
                                    class="ml-2 text-slate-500 dark:text-slate-400"
                                    style="font-variant-numeric: tabular-nums;"
                                >{{ $fmt($chain->toAmount) }}</span>
                            </p>
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                <a
                                    href="{{ route('transactions.show', ['transactionId' => $chain->fromTransactionId]) }}"
                                    wire:navigate
                                    class="underline-offset-2 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:focus-visible:ring-slate-100"
                                    data-testid="chains-index-from-tx-link-{{ $chain->chainLinkId }}"
                                >{{ Lang::get('chains::index.open_from_row') }}</a>
                                <span aria-hidden="true" class="mx-1">·</span>
                                <a
                                    href="{{ route('transactions.show', ['transactionId' => $chain->toTransactionId]) }}"
                                    wire:navigate
                                    class="underline-offset-2 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:focus-visible:ring-slate-100"
                                    data-testid="chains-index-to-tx-link-{{ $chain->chainLinkId }}"
                                >{{ Lang::get('chains::index.open_to_row') }}</a>
                            </p>
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                {{ $chain->fromPostedAt->translatedFormat('d M Y') }} → {{ $chain->toPostedAt->translatedFormat('d M Y') }}
                            </p>
                        </div>
                        <span
                            class="shrink-0 inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium {{ $tierClasses }}"
                            aria-label="{{ Lang::get('chains::index.state_aria', ['state' => $chain->state]) }}"
                        >{{ ucfirst($chain->state) }}</span>
                    </div>
                </li>
            @endforeach
        </ul>
        </div>{{-- /overflow-x-scroll-wrapper --}}
    @endif
</div>
