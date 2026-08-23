@use('Modules\Core\Public\Support\Lang')
@use('Modules\FX\Public\Support\BundledRates')
@php
    use Modules\Ledger\Public\ValueObjects\Money;

    $baseCurrency = $netWorth->currency;

    $fmt = static fn (int $minor): string => Money::ofMinor($minor, $baseCurrency)->format();

    $nativeFmt = static fn (int $minor, string $currency): string => Money::ofMinor($minor, $currency)->format();

    $amountClass = static fn (int $minor): string => $minor < 0
        ? 'text-rose-600 dark:text-rose-400'
        : 'text-slate-900 dark:text-slate-100';

    // Conversion is active when ratesSource is non-null (at least one non-base account was converted).
    $conversionActive = $netWorth->ratesSource !== null;

    // Human-readable provider label for the disclosure copy (UI-SPEC §7.2).
    $sourceLabel = static fn (?string $source): string => match ($source) {
        'ecb' => 'ECB',
        'frankfurter' => 'Frankfurter',
        BundledRates::SOURCE => Lang::get('core::net_worth.source_bundled'),
        'transaction' => Lang::get('core::net_worth.source_transaction'),
        null, '' => Lang::get('core::net_worth.source_fallback'),
        default => ucfirst($source),
    };

    // Stale-note copy depends on the rate's provenance (UI-SPEC §7.2): a bundled
    // snapshot tells the user to enable online refresh; a merely-old online rate
    // (staleness is age-based, independent of source) just notes its age.
    $staleNote = static fn (?string $source): string => $source === BundledRates::SOURCE
        ? Lang::get('core::net_worth.stale_bundled')
        : Lang::get('core::net_worth.stale_old');

    // The stored rate is a DECIMAL(18,8) string or a "num/den" fraction
    // (brick/money cross-rate). Render it as a fixed 4-decimal value for the
    // popover — display only, never used for money math.
    $fmtRate = static function (?string $rate): ?string {
        if ($rate === null || $rate === '') {
            return null;
        }
        if (str_contains($rate, '/')) {
            [$num, $den] = array_pad(explode('/', $rate, 2), 2, '');
            if (! is_numeric($num) || ! is_numeric($den) || (float) $den === 0.0) {
                return null;
            }
            $value = (float) $num / (float) $den;
        } elseif (is_numeric($rate)) {
            $value = (float) $rate;
        } else {
            return null;
        }

        return number_format($value, 4, '.', '');
    };
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
                    <p class="text-xs uppercase tracking-wide text-slate-400 dark:text-slate-500">{{ Lang::get('core::net_worth.heading') }}</p>

                    {{-- Total figure with FX disclosure affordance --}}
                    <p class="mt-1 text-3xl font-semibold {{ $amountClass($netWorth->totalMinor) }}" style="font-variant-numeric: tabular-nums;">
                        {{ $fmt($netWorth->totalMinor) }}
                        @if ($conversionActive)
                            {{-- Disclosure trigger: click reveals source · as-of date.
                                 anchor-name ties the popover to this trigger (UI-SPEC §5.4). --}}
                            <button type="button"
                                    class="fx-disclosure-trigger"
                                    style="anchor-name: --fx-net;"
                                    aria-label="{{ Lang::get('core::net_worth.rate_details') }}"
                                    x-data
                                    x-on:click="$refs.fxPopNetworth.showPopover()">
                                <span class="fx-icon {{ $netWorth->hasStaleRates ? 'fx-icon--stale' : '' }}" aria-hidden="true"></span>
                            </button>
                            {{-- Native HTML Popover API — no JS library needed (UI-SPEC §5.4/§6.4).
                                 The total mixes currencies, so it summarises source + as-of
                                 rather than claiming a single rate; per-pair rates live on the
                                 breakdown rows below. --}}
                            <div popover id="fx-pop-networth" x-ref="fxPopNetworth" class="fx-popover" style="position-anchor: --fx-net; position-area: bottom span-right; position-try-fallbacks: flip-inline, flip-block, flip-inline flip-block; margin: 6px 0 0;">
                                @if ($netWorth->ratesAsOf !== null)
                                    <p class="fx-rate">{{ Lang::get('core::net_worth.converted_to', ['currency' => $baseCurrency]) }}</p>
                                    <p class="fx-source">{{ $sourceLabel($netWorth->ratesSource) }} · {{ Lang::get('core::net_worth.as_of', ['date' => $netWorth->ratesAsOf->translatedFormat('d M Y')]) }}</p>
                                    @if ($netWorth->hasStaleRates)
                                        <p class="fx-stale-note">{{ $staleNote($netWorth->ratesSource) }}</p>
                                    @endif
                                @endif
                            </div>
                        @endif
                    </p>

                    @php($accountCount = $netWorth->accountCount())
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        {{ Lang::choice('core::net_worth.across', $accountCount, ['count' => $accountCount]) }}
                        @if ($netWorth->balancesWithoutRate > 0)
                            {{-- No-rate fallback (§7.4 UI-SPEC) — replaces old "excludes non-EUR balances" span --}}
                            <span style="color: var(--color-amber);">{{ Lang::choice('core::net_worth.not_converted', $netWorth->balancesWithoutRate, ['count' => $netWorth->balancesWithoutRate]) }}</span>
                        @endif
                    </p>

                    @if ($conversionActive)
                        {{-- Global rates disclosure line — one per surface (UI-SPEC §5.2/§7.3) --}}
                        <p class="mt-0.5 text-xs" style="color: var(--color-text-faint);">
                            {{ Lang::get('core::net_worth.global_rates', ['date' => $netWorth->ratesAsOf?->translatedFormat('d M Y'), 'source' => $sourceLabel($netWorth->ratesSource)]) }}
                        </p>
                    @endif
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
                                    <span class="ml-1 text-xs text-slate-400 dark:text-slate-500">{{ Lang::get('core::net_worth.card_suffix') }}</span>
                                @endif
                            </span>
                            <span class="shrink-0 font-medium {{ $amountClass($account->balanceMinor) }}" style="font-variant-numeric: tabular-nums;">
                                {{-- Native amount as primary display --}}
                                {{ $nativeFmt($account->balanceMinor, $account->currency) }}
                                @if ($account->isConverted())
                                    {{-- Per-account: real base-equivalent + inline FX trigger carrying
                                         this pair's actual rate (UI-SPEC §5.2/§5.4) --}}
                                    <span class="ml-1 text-xs" style="color: var(--color-text-faint);">
                                        ≈ {{ $fmt($account->baseEquivalentMinor) }}
                                        <button type="button"
                                                class="fx-disclosure-trigger fx-disclosure-trigger--inline"
                                                style="anchor-name: --fx-a{{ $lineKey }};"
                                                aria-label="{{ Lang::get('core::net_worth.rate_details_for', ['name' => $account->name]) }}"
                                                x-data
                                                x-on:click="$refs.{{ 'fxPop'.$lineKey }}.showPopover()">
                                            <span class="fx-icon {{ $account->fxIsStale ? 'fx-icon--stale' : '' }}" aria-hidden="true"></span>
                                        </button>
                                        <div popover id="fx-pop-{{ $lineKey }}" x-ref="{{ 'fxPop'.$lineKey }}" class="fx-popover" style="position-anchor: --fx-a{{ $lineKey }}; position-area: bottom span-right; position-try-fallbacks: flip-inline, flip-block, flip-inline flip-block; margin: 6px 0 0;">
                                            @php($accountRate = $fmtRate($account->fxRate))
                                            @if ($accountRate !== null)
                                                <p class="fx-rate">{{ Lang::get('core::net_worth.rate_line', ['from' => $account->currency, 'rate' => $accountRate, 'to' => $baseCurrency]) }}</p>
                                            @endif
                                            @if ($account->fxAsOf !== null)
                                                <p class="fx-source">{{ $sourceLabel($account->fxSource) }} · {{ Lang::get('core::net_worth.as_of', ['date' => $account->fxAsOf->translatedFormat('d M Y')]) }}</p>
                                            @endif
                                            @if ($account->fxIsStale)
                                                <p class="fx-stale-note">{{ $staleNote($account->fxSource) }}</p>
                                            @endif
                                        </div>
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
