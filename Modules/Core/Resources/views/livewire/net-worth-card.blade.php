@php
    use Modules\Ledger\Public\ValueObjects\Money;

    $baseCurrency = $netWorth->currency;

    // Format a minor amount in the base reporting currency.
    $fmt = static fn (int $minor): string => Money::ofMinor($minor, $baseCurrency)->format('nl_NL');

    // Format a minor amount in a given (native) currency.
    $nativeFmt = static fn (int $minor, string $currency): string => Money::ofMinor($minor, $currency)->format('nl_NL');

    $amountClass = static fn (int $minor): string => $minor < 0
        ? 'text-rose-600 dark:text-rose-400'
        : 'text-slate-900 dark:text-slate-100';

    // Conversion is active when ratesSource is non-null (at least one non-base account was converted).
    $conversionActive = $netWorth->ratesSource !== null;
@endphp

<div>
    @if ($netWorth->hasAccounts())
        <section class="rounded-lg border border-slate-200 bg-white p-6 dark:border-slate-700 dark:bg-slate-950" aria-label="Net worth">
            <div class="flex items-start justify-between gap-4">
                <div class="min-w-0">
                    <p class="text-xs uppercase tracking-wide text-slate-400 dark:text-slate-500">Net worth</p>

                    {{-- Total figure with FX disclosure affordance (D-11 / FX-04) --}}
                    <p class="mt-1 text-3xl font-semibold {{ $amountClass($netWorth->totalMinor) }}" style="font-variant-numeric: tabular-nums;">
                        {{ $fmt($netWorth->totalMinor) }}
                        @if ($conversionActive)
                            {{-- Disclosure trigger: click reveals rate · source · as-of date (D-11) --}}
                            <button type="button"
                                    class="fx-disclosure-trigger"
                                    aria-label="Rate details"
                                    x-data
                                    x-on:click="$refs.fxPopNetworth.showPopover()">
                                <span class="fx-icon {{ $netWorth->hasStaleRates ? 'fx-icon--stale' : '' }}" aria-hidden="true"></span>
                            </button>
                            {{-- Native HTML Popover API — no JS library needed (UI-SPEC §5.4/§6.4) --}}
                            <div popover id="fx-pop-networth" x-ref="fxPopNetworth" class="fx-popover">
                                @if ($netWorth->ratesSource !== null && $netWorth->ratesAsOf !== null)
                                    <p class="fx-rate">{{ $netWorth->ratesAsOf->format('Y-m-d') }}</p>
                                    <p class="fx-source">{{ $netWorth->ratesSource }} · as of {{ $netWorth->ratesAsOf->format('d M Y') }}</p>
                                    @if ($netWorth->hasStaleRates)
                                        <p class="fx-stale-note">Using bundled rate. Enable online refresh for current rates.</p>
                                    @endif
                                @endif
                            </div>
                        @endif
                    </p>

                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        across {{ count($netWorth->accounts) }} {{ count($netWorth->accounts) === 1 ? 'account' : 'accounts' }}
                        @if ($netWorth->accountsWithoutRate > 0)
                            {{-- No-rate fallback (§7.4 UI-SPEC) — replaces old "excludes non-EUR balances" span --}}
                            <span style="color: var(--color-amber);">· {{ $netWorth->accountsWithoutRate }} {{ $netWorth->accountsWithoutRate === 1 ? 'account' : 'accounts' }} not converted — no rate available</span>
                        @endif
                    </p>

                    @if ($conversionActive)
                        {{-- Global rates disclosure line — one per surface (D-11 / UI-SPEC §5.2/§7.3) --}}
                        <p class="mt-0.5 text-xs" style="color: var(--color-text-faint);">
                            rates as of {{ $netWorth->ratesAsOf?->format('d M Y') }} from {{ $netWorth->ratesSource }}
                        </p>
                    @endif
                </div>
                <button
                    type="button"
                    wire:click="toggle"
                    class="shrink-0 rounded-md border border-slate-200 bg-white px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300 dark:hover:bg-slate-800"
                >{{ $expanded ? 'Hide' : 'Breakdown' }}</button>
            </div>

            @if ($expanded)
                <ul class="mt-4 space-y-1.5 border-t border-slate-100 pt-4 dark:border-slate-800">
                    @foreach ($netWorth->accounts as $account)
                        <li class="flex items-center justify-between gap-3 text-sm">
                            <span class="min-w-0 flex-1 truncate text-slate-700 dark:text-slate-300">
                                {{ $account->name }}
                                @if ($account->isLiability)
                                    <span class="ml-1 text-xs text-slate-400 dark:text-slate-500">(card)</span>
                                @endif
                            </span>
                            <span class="shrink-0 font-medium {{ $amountClass($account->balanceMinor) }}" style="font-variant-numeric: tabular-nums;">
                                {{-- Native amount as primary display (D-02) --}}
                                {{ $nativeFmt($account->balanceMinor, $account->currency) }}
                                @if ($account->currency !== $baseCurrency)
                                    {{-- Per-account: faint base-equivalent + inline FX trigger (D-02 / UI-SPEC §5.2) --}}
                                    <span class="ml-1 text-xs" style="color: var(--color-text-faint);">
                                        ≈ {{ $fmt($account->balanceMinor) }}
                                        <button type="button"
                                                class="fx-disclosure-trigger fx-disclosure-trigger--inline"
                                                aria-label="Rate details for {{ $account->name }}"
                                                x-data
                                                x-on:click="$refs.{{ 'fxPop'.$account->accountId }}.showPopover()">
                                            <span class="fx-icon {{ $netWorth->hasStaleRates ? 'fx-icon--stale' : '' }}" aria-hidden="true"></span>
                                        </button>
                                        <div popover id="fx-pop-{{ $account->accountId }}" x-ref="{{ 'fxPop'.$account->accountId }}" class="fx-popover">
                                            @if ($netWorth->ratesSource !== null && $netWorth->ratesAsOf !== null)
                                                <p class="fx-source">{{ $netWorth->ratesSource }} · as of {{ $netWorth->ratesAsOf->format('d M Y') }}</p>
                                                @if ($netWorth->hasStaleRates)
                                                    <p class="fx-stale-note">Using bundled rate. Enable online refresh for current rates.</p>
                                                @endif
                                            @endif
                                        </div>
                                    </span>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endif
</div>
