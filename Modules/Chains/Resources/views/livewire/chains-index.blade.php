@use('Modules\Core\Public\Support\Lang')
{{-- /chains — every settled charge, with the payments that fed into it
     branching beneath.

     A chain is a fan-in: several purchases collected into one settlement.
     This page used to render one flat card per link, so an eight-purchase ICS
     collection became eight near-identical cards, each repeating the same
     settlement name, amount and date, behind a 480px min-width that forced
     phones to scroll sideways. Grouping by settlement shows the shape the
     feature exists to reveal, and lets the card reflow instead of scroll. --}}
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

<div class="mx-auto max-w-3xl px-4 py-10 sm:py-12">
    <header class="mb-8">
        <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-2">
            <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">{{ Lang::get('chains::index.heading') }}</h1>
            <div class="flex items-center gap-3 text-xs text-slate-500 dark:text-slate-400">
                <a
                    href="{{ route('chains.review') }}"
                    class="tap-link hover:text-slate-700 dark:hover:text-slate-200"
                    data-testid="chains-index-review-link"
                >{{ Lang::get('chains::index.review_link') }}</a>
                <a
                    href="{{ route('chains.hints') }}"
                    class="tap-link hover:text-slate-700 dark:hover:text-slate-200"
                    data-testid="chains-index-hints-link"
                >{{ Lang::get('chains::index.hints_link') }}</a>
            </div>
        </div>
        <p class="mt-2 max-w-prose text-sm text-slate-500 dark:text-slate-400">
            {{ Lang::get('chains::index.subtitle') }}
        </p>
    </header>

    @if (count($settlements) === 0)
        <x-core::empty-state
            data-testid="chains-index-empty"
            :heading="Lang::get('chains::index.empty_heading')"
            :body="Lang::get('chains::index.empty_body')"
        />
    @else
        <ul class="space-y-4" data-testid="chains-index-list">
            @foreach ($settlements as $settlement)
                @php
                    $tierClasses = match ($settlement->state) {
                        'confirmed' => 'bg-slate-50 text-slate-600 ring-1 ring-slate-200 dark:bg-slate-900 dark:text-slate-300 dark:ring-slate-700',
                        'candidate' => 'bg-amber-50 text-amber-800 ring-1 ring-amber-200 dark:bg-amber-950 dark:text-amber-200 dark:ring-amber-800',
                        default => 'bg-slate-50 text-slate-500 ring-1 ring-slate-200 dark:bg-slate-900 dark:text-slate-400 dark:ring-slate-700',
                    };
                @endphp
                <li
                    class="overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-950"
                    data-testid="chain-settlement-{{ $settlement->transactionId }}"
                >
                    {{-- The settlement leads: it is the charge that actually
                         left the account, and the one line a user recognises
                         from their statement. --}}
                    <div class="flex items-start justify-between gap-3 border-b border-slate-100 bg-slate-50/60 px-4 py-3 dark:border-slate-800 dark:bg-slate-900/40">
                        <div class="chain-name">
                            <p class="chain-name text-sm font-semibold text-slate-900 dark:text-slate-100">
                                @if ($settlement->counterpartySlug !== null)
                                    <a
                                        href="{{ route('counterparties.profile', ['slug' => $settlement->counterpartySlug]) }}"
                                        wire:navigate
                                        class="underline-offset-2 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:focus-visible:ring-slate-100"
                                        data-testid="chains-index-to-counterparty-link-{{ $settlement->transactionId }}"
                                    >{{ $settlement->counterparty ?: Lang::get('chains::index.no_counterparty') }}</a>
                                @else
                                    <span data-testid="chains-index-to-counterparty-text-{{ $settlement->transactionId }}">{{ $settlement->counterparty ?: Lang::get('chains::index.no_counterparty') }}</span>
                                @endif
                            </p>
                            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                                <a
                                    href="{{ route('transactions.show', ['transactionId' => $settlement->transactionId]) }}"
                                    wire:navigate
                                    class="underline-offset-2 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:focus-visible:ring-slate-100"
                                    data-testid="chains-index-to-tx-link-{{ $settlement->transactionId }}"
                                >{{ $settlement->postedAt->translatedFormat('d M Y') }}</a>
                                <span aria-hidden="true" class="mx-1">·</span>{{ $kindLabel($settlement->kind) }}
                            </p>
                        </div>
                        <p
                            class="shrink-0 text-base font-semibold text-slate-900 dark:text-slate-100"
                            style="font-variant-numeric: tabular-nums;"
                        >{{ $fmt($settlement->amount) }}</p>
                    </div>

                    {{-- One rail down the whole group, rather than a pseudo
                         element per row: the fan-in reads as many payments
                         feeding one charge, and there is no per-item height
                         arithmetic to get wrong at different text sizes. --}}
                    <ul
                        class="chain-legs my-2 ml-6 mr-4"
                        data-testid="chain-legs-{{ $settlement->transactionId }}"
                    >
                        @foreach ($settlement->legs as $leg)
                            <li
                                class="chain-leg flex items-baseline gap-3 py-1.5 pl-4"
                                data-testid="chain-row-{{ $leg->chainLinkId }}"
                            >
                                <span class="chain-name flex-1 text-sm text-slate-700 dark:text-slate-200">
                                    @if ($leg->fromCounterpartySlug !== null)
                                        <a
                                            href="{{ route('counterparties.profile', ['slug' => $leg->fromCounterpartySlug]) }}"
                                            wire:navigate
                                            class="underline-offset-2 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:focus-visible:ring-slate-100"
                                            data-testid="chains-index-from-counterparty-link-{{ $leg->chainLinkId }}"
                                        >{{ $leg->fromCounterparty ?: Lang::get('chains::index.no_counterparty') }}</a>
                                    @else
                                        <span data-testid="chains-index-from-counterparty-text-{{ $leg->chainLinkId }}">{{ $leg->fromCounterparty ?: Lang::get('chains::index.no_counterparty') }}</span>
                                    @endif
                                </span>
                                <span class="flex shrink-0 items-baseline gap-3 text-xs text-slate-500 dark:text-slate-400">
                                    <a
                                        href="{{ route('transactions.show', ['transactionId' => $leg->fromTransactionId]) }}"
                                        wire:navigate
                                        class="hidden underline-offset-2 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 sm:inline dark:focus-visible:ring-slate-100"
                                        data-testid="chains-index-from-tx-link-{{ $leg->chainLinkId }}"
                                    >{{ $leg->fromPostedAt->translatedFormat('d M') }}</a>
                                    <span
                                        class="text-sm text-slate-900 dark:text-slate-100"
                                        style="font-variant-numeric: tabular-nums;"
                                    >{{ $fmt($leg->fromAmount) }}</span>
                                </span>
                            </li>
                        @endforeach
                    </ul>

                    {{-- Count and total of the legs, stated and not judged.
                         An earlier version subtracted the two and called the
                         difference "not yet accounted for"; linked purchases
                         routinely exceed the charge they settle into, so that
                         line rendered an overshoot as a shortfall on nearly
                         every card. Facts here, no arithmetic claim. --}}
                    <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 border-t border-slate-100 px-4 py-2 text-xs dark:border-slate-800">
                        <p class="text-slate-500 dark:text-slate-400">
                            {{-- choice(), not get(): a pipe-pluralised line read
                                 through get() renders its raw source, braces
                                 and pipe included, straight onto the page. --}}
                            {{ Lang::choice('chains::index.leg_count', count($settlement->legs)) }}
                            <span aria-hidden="true" class="mx-1">&middot;</span>
                            <span
                                style="font-variant-numeric: tabular-nums;"
                                data-testid="chain-leg-total-{{ $settlement->transactionId }}"
                            >{{ $fmt($settlement->legTotal) }}</span>
                        </p>
                        <span
                            class="inline-flex shrink-0 items-center rounded-full px-2 py-0.5 font-medium {{ $tierClasses }}"
                            aria-label="{{ Lang::get('chains::index.state_aria', ['state' => $settlement->state]) }}"
                        >{{ ucfirst($settlement->state) }}</span>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</div>
