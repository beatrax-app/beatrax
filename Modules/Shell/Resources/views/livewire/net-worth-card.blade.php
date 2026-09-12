@use('Modules\Core\Public\Support\Lang')
@php
    use Modules\Ledger\Public\ValueObjects\Money;

    $baseCurrency = $netWorth->currency;

    $fmt = static fn (int $minor): string => Money::ofMinor($minor, $baseCurrency)->format();

    $nativeFmt = static fn (int $minor, string $currency): string => Money::ofMinor($minor, $currency)->format();

    $amountClass = static fn (int $minor): string => $minor < 0
        ? 'text-rose-600 dark:text-rose-400'
        : 'text-slate-900 dark:text-slate-100';
@endphp

<div>
    @if ($netWorth->hasAccounts())
        <x-core::card tag="section" aria-label="{{ Lang::get('core::net_worth.aria') }}">
            {{-- flex-wrap: the figure is text-3xl and has no break opportunity
                 inside it, and its column is min-w-0 beside a shrink-0 button,
                 so it overflowed VISIBLY and painted under the button. Measured
                 on an iPhone 12 mini: €1,727.38 needed 170px in a 137px column
                 and the last digit sat under "Breakdown". --}}
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    {{-- A div, not a p: the tip's panel is a div, and a
                         <div popover> inside a <p> closes the paragraph in the
                         parser, which would put the figure below outside it. --}}
                    <div class="text-xs uppercase tracking-wide text-slate-600 dark:text-slate-400">{{ Lang::get('core::net_worth.heading') }}&nbsp;<x-core::help-tip
                        topic="net-worth"
                        :label="Lang::get('core::net_worth.heading')"
                        :body="Lang::get('core::help.net_worth')"
                    /></div>

                    <p class="mt-1 text-3xl font-semibold {{ $amountClass($netWorth->totalMinor) }}" style="font-variant-numeric: tabular-nums;">
                        {{ $fmt($netWorth->totalMinor) }}
                    </p>

                    @php($accountCount = $netWorth->accountCount())
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        {{ Lang::choice('core::net_worth.across', $accountCount, ['count' => $accountCount]) }}
                        @if ($netWorth->balancesWithoutRate > 0)
                            {{-- No-rate fallback (§7.4 UI-SPEC) — replaces old "excludes non-EUR balances" span --}}
                            <span style="color: var(--color-amber);">{{ Lang::choice('core::net_worth.not_converted', $netWorth->balancesWithoutRate, ['count' => $netWorth->balancesWithoutRate]) }}</span>
                        @endif
                    </p>

                    {{-- Every rate, source, as-of date and stale note on this
                         card comes from here. The card kept private copies of
                         all four, which is how its total came to be dated by
                         its freshest leg rather than its oldest. --}}
                    <x-core::fx-disclosure
                        :disclosure="$netWorth->conversion"
                        id="net-worth"
                        :label="Lang::get('core::net_worth.heading')"
                        :online-rates="(bool) $fxOnlineEnabled"
                        class="mt-0.5 block text-xs"
                        style="color: var(--color-text-faint);"
                    />
                </div>
                <x-core::secondary-button
                    size="sm"
                    class="shrink-0"
                    wire:click="toggle"
                >{{ $expanded ? Lang::get('core::net_worth.toggle_hide') : Lang::get('core::net_worth.toggle_breakdown') }}</x-core::secondary-button>
            </div>

            @if ($expanded)
                <ul class="mt-4 space-y-1.5 border-t border-slate-100 pt-4 dark:border-slate-800">
                    @foreach ($netWorth->accounts as $account)
                        {{-- One account can hold several currencies and yields a
                             line each, so the popover anchor, id and x-ref are
                             keyed by account AND currency or the two collide. --}}
                        @php($lineKey = $account->accountId . $account->currency)
                        <li class="flex items-center justify-between gap-3 text-sm">
                            <span class="min-w-0 flex-1 truncate text-slate-700 dark:text-slate-300">
                                {{ $account->name }}
                                @if ($account->isLiability)
                                    <span class="ml-1 text-xs text-slate-600 dark:text-slate-400">{{ Lang::get('core::net_worth.card_suffix') }}</span>
                                @endif
                            </span>
                            <span class="shrink-0 font-medium {{ $amountClass($account->balanceMinor) }}" style="font-variant-numeric: tabular-nums;">
                                {{-- Native amount as primary display --}}
                                {{ $nativeFmt($account->balanceMinor, $account->currency) }}
                                @if ($account->isConverted())
                                    <span class="ml-1 text-xs" style="color: var(--color-text-faint);">
                                        ≈ {{ $fmt($account->baseEquivalentMinor) }}
                                        <x-core::fx-disclosure
                                            :disclosure="$account->disclosure($baseCurrency)"
                                            id="account-{{ $lineKey }}"
                                            :label="$account->name"
                                            :online-rates="(bool) $fxOnlineEnabled"
                                        />
                                    </span>
                                @elseif ($account->hasNoRate($baseCurrency))
                                    {{-- No rate at all for this pair — show the native amount
                                         only, with a calm amber note in the base-equivalent slot. --}}
                                    <span class="ml-1 text-xs" style="color: var(--color-amber);">{{ Lang::get('core::net_worth.no_rate_available') }}</span>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-core::card>
    @endif
</div>
