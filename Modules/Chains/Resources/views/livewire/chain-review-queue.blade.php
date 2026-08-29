{{-- /chains/review page.

     Renders the user's open `state='candidate'` chain_links as a calm
     list of rows, sorted by confidence DESC then id DESC. Each row
     shows the from / to counterparties, the kind label ("PayPal
     funding" / "Bulk iDEAL settlement"), and Confirm + Reject buttons
     that delegate to the same Public action classes the chain drawer
     uses.

     Auto-promotion hint: the "One more confirm…" inline copy
     renders only on rows where `confirmsRemaining === 1` — the only
     proactive nudge about the learning loop.

     Each row uses Blade's default `{{ }}` escaping for all
     counterparty / amount / date interpolation. No `{!! !!}` raw-HTML
     anywhere.

     Copy is locked verbatim against 05-UI-SPEC.md § Copywriting
     Contract → "/chains/review page". --}}

@use('Modules\Core\Public\Support\Lang')
@php
    use Modules\Ledger\Public\ValueObjects\Money;

    $fmt = static fn (Money $money): string => $money->format();

    $kindLabel = static function (string $kind): string {
        if ($kind === \Modules\Chains\Public\Enums\ChainLinkKind::PaypalFunding->value) {
            return Lang::get('chains::review.kind.paypal_funding');
        }
        if ($kind === \Modules\Chains\Public\Enums\ChainLinkKind::IcsBulkSettle->value) {
            return Lang::get('chains::review.kind.ics_bulk_settle');
        }
        return $kind;
    };
@endphp

<div class="mx-auto max-w-5xl px-4 py-12">
    <header class="mb-12">
        <div class="flex flex-wrap items-baseline justify-between gap-4">
            <x-core::page-heading>{{ Lang::get('chains::review.heading') }}</x-core::page-heading>
            @if (($hintCount ?? 0) > 0)
                <a
                    href="{{ route('chains.hints') }}"
                    class="tap-link text-xs text-amber-700 hover:text-amber-900 dark:text-amber-400 dark:hover:text-amber-200"
                    data-testid="chain-hints-link"
                >{{ Lang::choice('chains::review.hint', $hintCount) }} →</a>
            @endif
        </div>
        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
            {{ Lang::get('chains::review.subtitle') }}
        </p>
    </header>

    @if ($actionError)
        <x-core::alert
            tone="danger"
            class="mb-6"
            aria-live="polite" aria-atomic="true"
            data-testid="chain-review-action-error"
        >
            {{ $actionError }}
        </x-core::alert>
    @endif

    @if (count($candidates) === 0)
        <x-core::empty-state
            :heading="Lang::get('chains::review.empty_heading')"
            :body="Lang::get('chains::review.empty_body')"
        />
    @else
        {{-- overflow-x: auto wrapper so dense chain rows scroll horizontally at phone width --}}
        <div class="overflow-x-scroll-wrapper" style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
        <ul class="space-y-4" style="min-width: 480px;">
            @foreach ($candidates as $row)
                <li class="rounded-lg border border-slate-200 bg-slate-50 p-4 opacity-90 dark:bg-slate-900 dark:border-slate-700">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0 flex-1">
                            {{-- Each leg's counterparty name links to
                                 its profile when the row has been
                                 resolved by the CounterpartyResolver
                                 chain; plain text otherwise. --}}
                            <p class="text-sm text-slate-900 dark:text-slate-100">
                                @if ($row->fromCounterpartySlug !== null)
                                    <a
                                        href="{{ route('counterparties.profile', ['slug' => $row->fromCounterpartySlug]) }}"
                                        wire:navigate
                                        class="tap-link underline-offset-2 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:focus-visible:ring-slate-100"
                                        data-testid="chain-review-from-counterparty-link-{{ $row->chainLinkId }}"
                                    >{{ $row->fromCounterparty }}</a>
                                @else
                                    <span data-testid="chain-review-from-counterparty-text-{{ $row->chainLinkId }}">{{ $row->fromCounterparty }}</span>
                                @endif
                                <span class="text-slate-500 dark:text-slate-400" style="font-variant-numeric: tabular-nums;">{{ $fmt($row->fromAmount) }}</span>
                                <span aria-hidden="true" class="mx-1 text-slate-500 dark:text-slate-400">←</span>
                                @if ($row->toCounterpartySlug !== null)
                                    <a
                                        href="{{ route('counterparties.profile', ['slug' => $row->toCounterpartySlug]) }}"
                                        wire:navigate
                                        class="tap-link underline-offset-2 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:focus-visible:ring-slate-100"
                                        data-testid="chain-review-to-counterparty-link-{{ $row->chainLinkId }}"
                                    >{{ $row->toCounterparty }}</a>
                                @else
                                    <span data-testid="chain-review-to-counterparty-text-{{ $row->chainLinkId }}">{{ $row->toCounterparty }}</span>
                                @endif
                                <span class="text-slate-500 dark:text-slate-400" style="font-variant-numeric: tabular-nums;">{{ $fmt($row->toAmount) }}</span>
                            </p>
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                {{ $kindLabel($row->kind) }} · {{ $row->fromPostedAt->translatedFormat('d M Y') }} → {{ $row->toPostedAt->translatedFormat('d M Y') }}
                            </p>
                            @if ($row->confirmsRemaining === 1)
                                <p class="mt-1 flex items-center gap-1 text-xs text-emerald-700 dark:text-emerald-200">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                    </svg>
                                    {{ Lang::get('chains::review.auto_confirm_nudge') }}
                                </p>
                            @endif
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <button
                                type="button"
                                wire:click="confirm({{ $row->chainLinkId }})"
                                aria-label="{{ Lang::get('chains::review.confirm_aria', ['id' => $row->chainLinkId]) }}"
                                class="inline-flex items-center gap-1 rounded-md bg-emerald-700 px-2.5 py-1 text-xs font-medium text-white hover:bg-emerald-800 focus-visible:ring-2 focus-visible:ring-emerald-700 focus-visible:ring-offset-2 dark:bg-emerald-700 dark:hover:bg-emerald-800"
                            >{{ Lang::get('chains::review.confirm') }}</button>
                            <button
                                type="button"
                                wire:click="reject({{ $row->chainLinkId }})"
                                aria-label="{{ Lang::get('chains::review.reject_aria', ['id' => $row->chainLinkId]) }}"
                                class="inline-flex items-center gap-1 rounded-md bg-rose-50 px-2.5 py-1 text-xs font-medium text-rose-600 hover:bg-rose-100 focus-visible:ring-2 focus-visible:ring-rose-600 focus-visible:ring-offset-2 dark:bg-rose-950 dark:text-rose-500 dark:hover:bg-rose-900"
                            >{{ Lang::get('chains::review.reject') }}</button>
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
        </div>{{-- /overflow-x-scroll-wrapper --}}

        @if (count($candidates) >= 25)
            @php
                $last = $candidates[count($candidates) - 1] ?? null;
            @endphp
            @if ($last !== null)
                <div class="mt-6 flex justify-center">
                    <x-core::secondary-button wire:click="loadMore({{ $last->chainLinkId }}, '{{ number_format($last->confidence, 3, '.', '') }}')">{{ Lang::get('chains::review.show_more') }}</x-core::secondary-button>
                </div>
            @endif
        @endif
    @endif
</div>
