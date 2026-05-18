{{--
    /forecast page — Wave 2 baseline-only surface.

    Renders the page heading + subheading + helper link, the
    per-account tab bar, the 30/60/90 horizon segmented control, and
    a single per-account rangeArea chart for the baseline projection.
    The scenario picker, side-by-side panel, buffer editor trigger,
    shortfall band overlay, and confidence legend land in later
    waves.
--}}
@php
    use Modules\Forecasting\Public\Dto\ForecastDto;

    $eurFmt = static function (int $minor, string $currency = 'EUR'): string {
        $value = $minor / 100;
        $formatter = new \NumberFormatter('nl_NL', \NumberFormatter::CURRENCY);
        $rendered = $formatter->formatCurrency($value, $currency);
        return $rendered === false ? number_format($value, 2, ',', '.') : $rendered;
    };
@endphp

<div class="mx-auto max-w-7xl px-4 py-12">
    <header class="mb-8 flex items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-slate-900">Forecast</h1>
            <p class="mt-2 max-w-prose text-sm text-slate-500">
                Where your balance is heading — over the next 30, 60, or 90 days.
            </p>
        </div>
        <a
            href="{{ url('/settings') }}#forecast-buffers"
            class="text-sm text-slate-500 underline-offset-2 hover:text-slate-900 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
        >Adjust buffers &rarr;</a>
    </header>

    @if ($isEmpty)
        <section class="rounded-lg border border-slate-200 bg-white p-8">
            <h2 class="text-base font-semibold text-slate-900">No forecast data yet</h2>
            <p class="mt-2 max-w-prose text-sm text-slate-500">
                Connect an account or approve a recurring series to see your projected balance over the coming weeks.
            </p>
            <p class="mt-3 max-w-prose text-sm text-slate-500">
                Start by
                <a href="{{ url('/import') }}" class="text-slate-900 underline underline-offset-2 hover:text-slate-700">importing a statement</a>
                or
                <a href="{{ url('/recurring/review') }}" class="text-slate-900 underline underline-offset-2 hover:text-slate-700">reviewing recurring patterns</a>.
            </p>
        </section>
    @else
        @if (count($accounts) > 0)
            <nav class="mb-6 flex flex-wrap items-center gap-2 border-b border-slate-200" role="tablist" aria-label="Account">
                @foreach ($accounts as $account)
                    <button
                        type="button"
                        role="tab"
                        aria-selected="{{ $selectedAccountId === $account['id'] ? 'true' : 'false' }}"
                        wire:click="setAccount('{{ $account['id'] }}')"
                        @class([
                            'px-3 py-2 text-sm',
                            'border-b-2 border-slate-900 font-medium text-slate-900' => $selectedAccountId === $account['id'],
                            'border-b-2 border-transparent text-slate-500 hover:text-slate-900' => $selectedAccountId !== $account['id'],
                        ])
                    >{{ $account['name'] }}</button>
                @endforeach
            </nav>
        @endif

        <div class="mb-6 inline-flex items-center gap-1 rounded-md border border-slate-200 bg-white p-1" role="radiogroup" aria-label="Forecast horizon">
            @foreach ([30, 60, 90] as $option)
                <button
                    type="button"
                    role="radio"
                    aria-checked="{{ $horizon === $option ? 'true' : 'false' }}"
                    wire:click="setHorizon({{ $option }})"
                    @class([
                        'rounded px-3 py-1.5 text-sm',
                        'bg-slate-900 text-white' => $horizon === $option,
                        'text-slate-500 hover:text-slate-900' => $horizon !== $option,
                    ])
                >{{ $option }} days</button>
            @endforeach
        </div>

        @if ($baseline instanceof ForecastDto)
            <section class="rounded-lg border border-slate-200 bg-white p-4">
                <header class="mb-3 flex items-baseline justify-between gap-4">
                    <div>
                        <h2 class="text-base font-semibold text-slate-900">{{ $selectedAccountName }} · Baseline</h2>
                        <p class="mt-1 text-sm text-slate-500" style="font-variant-numeric: tabular-nums;">
                            {{ $eurFmt($todayBalanceMinor, $defaultCurrency) }} today
                            &nbsp;&rarr;&nbsp;
                            {{ $eurFmt($horizonLowMinor, $defaultCurrency) }} &ndash; {{ $eurFmt($horizonHighMinor, $defaultCurrency) }} on day {{ $horizon }}
                        </p>
                    </div>
                    @if ($selectedAccountId !== null)
                        <div x-data="{ open: false }" class="relative">
                            <button
                                type="button"
                                x-on:click="open = ! open"
                                aria-haspopup="dialog"
                                aria-label="Edit minimum buffer for {{ $selectedAccountName }}"
                                class="text-sm text-slate-500 underline-offset-2 hover:text-slate-900 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
                                style="font-variant-numeric: tabular-nums;"
                            >
                                {{ $effectiveBufferMinor === null ? 'Buffer: not set' : 'Buffer: ' . $eurFmt($effectiveBufferMinor, $selectedAccountCurrency) }}
                            </button>
                            <div
                                x-show="open"
                                x-cloak
                                x-on:click.outside="open = false"
                                x-on:keydown.escape.window="open = false"
                                x-on:buffer-editor:saved.window="open = false"
                                class="absolute right-0 z-10 mt-1 w-64 rounded-md border border-slate-200 bg-white p-3 text-sm shadow-lg"
                            >
                                @livewire('forecasting.account-buffer-editor', [
                                    'accountId' => $selectedAccountId,
                                    'currentBufferMinor' => $effectiveBufferMinor,
                                    'currency' => $selectedAccountCurrency,
                                    'accountName' => $selectedAccountName,
                                ], key('buffer-editor-' . $selectedAccountId))
                            </div>
                        </div>
                    @endif
                </header>

                @foreach ($shortfallWindows as $window)
                    <p class="mb-2 flex items-center gap-1 text-xs text-rose-700" style="font-variant-numeric: tabular-nums;">
                        <span aria-hidden="true">↘</span>
                        <span>
                            Shortfall starts {{ $window->startsAt->format('d M') }} —
                            {{ $eurFmt(abs($window->lowestBalanceMinor - $window->bufferUsedMinor), $window->currency) }}
                            below your {{ $eurFmt($window->bufferUsedMinor, $window->currency) }} buffer
                        </span>
                    </p>
                @endforeach

                @include('forecasting::livewire.partials.range-area-chart', [
                    'forecast' => $baseline,
                    'panel' => 'baseline',
                    'apexOptions' => $apexOptions,
                    'chartElementId' => $chartElementId,
                ])
            </section>
        @endif
    @endif
</div>
