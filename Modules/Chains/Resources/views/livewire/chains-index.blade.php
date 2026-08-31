@use('Modules\Chains\Public\Enums\ChainLinkState')
@use('Modules\Core\Public\Support\Fmt')
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
    $fmt = static fn ($money) => $money->format();
@endphp

<div class="mx-auto max-w-3xl px-4 py-6">
    <header class="mb-8">
        <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-2">
            <x-core::page-heading>
                {{ Lang::get('chains::index.heading') }}
                <x-slot:tip>
                    <x-core::help-tip
                        topic="chains"
                        :label="Lang::get('chains::index.heading')"
                        :body="Lang::get('chains::help.index')"
                    />
                </x-slot:tip>
            </x-core::page-heading>
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
                    $settlementState = ChainLinkState::tryFrom($settlement->state);
                    $tierClasses = match ($settlementState) {
                        ChainLinkState::Confirmed => 'bg-slate-50 text-slate-600 ring-1 ring-slate-200 dark:bg-slate-900 dark:text-slate-300 dark:ring-slate-700',
                        ChainLinkState::Candidate => 'bg-amber-50 text-amber-800 ring-1 ring-amber-200 dark:bg-amber-950 dark:text-amber-200 dark:ring-amber-800',
                        default => 'bg-slate-50 text-slate-500 ring-1 ring-slate-200 dark:bg-slate-900 dark:text-slate-400 dark:ring-slate-700',
                    };
                    $stateLabel = $settlementState === null
                        ? $settlement->state
                        : Lang::get($settlementState->labelKey());
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
                                        class="tap-link underline-offset-2 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:focus-visible:ring-slate-100"
                                        data-testid="chains-index-settlement-counterparty-link-{{ $settlement->transactionId }}"
                                    >{{ $settlement->counterparty ?: Lang::get('chains::index.no_counterparty') }}</a>
                                @else
                                    <span data-testid="chains-index-settlement-counterparty-text-{{ $settlement->transactionId }}">{{ $settlement->counterparty ?: Lang::get('chains::index.no_counterparty') }}</span>
                                @endif
                            </p>
                            {{-- Both lines are destinations, and their halos are
                                 44px centred on 20px apart, so the date's covered
                                 the name's: a tap 16px left of the name's centre
                                 opened the TRANSACTION. The date went without a
                                 halo for one round and measured 80x17 on an
                                 iPhone. .chain-settlement-meta takes the pitch to
                                 44 on touch instead, so both fit. --}}
                            <p class="chain-settlement-meta mt-0.5 text-xs text-slate-600 dark:text-slate-400">
                                <a
                                    href="{{ route('transactions.show', ['transactionId' => $settlement->transactionId]) }}"
                                    wire:navigate
                                    class="tap-link underline-offset-2 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:focus-visible:ring-slate-100"
                                    data-testid="chains-index-settlement-tx-link-{{ $settlement->transactionId }}"
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
                                    @if ($leg->counterpartySlug !== null)
                                        <a
                                            href="{{ route('counterparties.profile', ['slug' => $leg->counterpartySlug]) }}"
                                            wire:navigate
                                            class="tap-link underline-offset-2 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:focus-visible:ring-slate-100"
                                            data-testid="chains-index-leg-counterparty-link-{{ $leg->chainLinkId }}"
                                        >{{ $leg->counterparty ?: Lang::get('chains::index.no_counterparty') }}</a>
                                    @else
                                        <span data-testid="chains-index-leg-counterparty-text-{{ $leg->chainLinkId }}">{{ $leg->counterparty ?: Lang::get('chains::index.no_counterparty') }}</span>
                                    @endif
                                </span>
                                <span class="flex shrink-0 items-baseline gap-3 text-xs text-slate-500 dark:text-slate-400">
                                    <a
                                        href="{{ route('transactions.show', ['transactionId' => $leg->transactionId]) }}"
                                        wire:navigate
                                        class="hidden underline-offset-2 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 sm:inline dark:focus-visible:ring-slate-100"
                                        data-testid="chains-index-leg-tx-link-{{ $leg->chainLinkId }}"
                                    >{{ $leg->postedAt->translatedFormat('d M') }}</a>
                                    <span
                                        class="text-sm text-slate-900 dark:text-slate-100"
                                        style="font-variant-numeric: tabular-nums;"
                                    >{{ $fmt($leg->amount) }}</span>
                                </span>
                            </li>
                        @endforeach
                        @if (count($settlement->legs) < $settlement->legCount)
                            <li class="chain-leg py-1.5 pl-4 text-xs text-slate-500 dark:text-slate-400" data-testid="chain-legs-more-{{ $settlement->transactionId }}">
                                {{ Lang::get('chains::index.legs_more', ['count' => Fmt::number($settlement->legCount - count($settlement->legs))]) }}
                            </li>
                        @endif
                    </ul>

                    {{-- Count and total of the legs, stated and not judged.
                         An earlier version subtracted the two and called the
                         difference "not yet accounted for"; linked purchases
                         routinely exceed the charge they settle into, so that
                         line rendered an overshoot as a shortfall on nearly
                         every card. Facts here, no arithmetic claim.

                         Both figures count EVERY leg, including the ones past
                         the card's display cap: taken from the listed legs
                         alone they contradicted the settlement heading above,
                         since one ICS statement covers up to 300 charges and
                         a page of links cut through the middle of it. --}}
                    <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 border-t border-slate-100 px-4 py-2 text-xs dark:border-slate-800">
                        <p class="text-slate-500 dark:text-slate-400">
                            {{-- choice(), not get(): a pipe-pluralised line read
                                 through get() renders its raw source, braces
                                 and pipe included, straight onto the page. --}}
                            {{ Lang::choice('chains::index.leg_count', $settlement->legCount) }}
                            <span aria-hidden="true" class="mx-1">&middot;</span>
                            {{-- One figure per currency: a settlement whose legs
                                 are not all in one currency has no single total,
                                 and adding them anyway threw. --}}
                            <span
                                style="font-variant-numeric: tabular-nums;"
                                data-testid="chain-leg-total-{{ $settlement->transactionId }}"
                            >{{ implode(' + ', array_map($fmt, $settlement->legTotals)) }}</span>
                        </p>
                        <span
                            role="img"
                            class="inline-flex shrink-0 items-center rounded-full px-2 py-0.5 font-medium {{ $tierClasses }}"
                            aria-label="{{ Lang::get('chains::index.state_aria', ['state' => $stateLabel]) }}"
                        >{{ $stateLabel }}</span>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</div>
