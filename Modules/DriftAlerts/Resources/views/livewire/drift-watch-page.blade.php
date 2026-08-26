@use('Modules\Core\Public\Navigation\Destination')
@use('Modules\Core\Public\Support\Lang')
@use('Modules\Core\Public\Support\Fmt')
{{--
    /drift/watch — Subscription Drift Watch overview. Approved subscriptions
    ranked by how much their price has crept up since the first observed charge,
    each with a sparkline of its amount history and a deep link into the
    series-detail chart. Blade default `{{ }}` escaping throughout; the
    sparkline is built from numeric data only.
--}}
@php
    use Modules\Ledger\Public\ValueObjects\Money;

    $fmt = static fn (int $minor, string $currency): string => Money::ofMinor($minor, $currency)
        ->format();

    $signed = static fn (int $minor, string $currency): string => ($minor >= 0 ? '+' : '−').$fmt(abs($minor), $currency);

    $deltaClass = [
        'up' => 'text-rose-600 dark:text-rose-400',
        'down' => 'text-emerald-600 dark:text-emerald-400',
        'flat' => 'text-slate-500 dark:text-slate-400',
    ];

    // Build a tiny SVG polyline (88×26) from a row's amount points.
    $sparkline = static function (array $points): string {
        $values = array_map(static fn (array $p): int => (int) $p['amount_minor'], $points);
        $n = count($values);
        if ($n < 2) {
            return '';
        }
        $min = min($values);
        $max = max($values);
        $w = 88;
        $h = 26;
        $pad = 3;
        $span = $max - $min;
        $coords = [];
        foreach ($values as $i => $v) {
            $x = $pad + ($i / ($n - 1)) * ($w - 2 * $pad);
            $y = $span === 0
                ? $h / 2
                : ($h - $pad) - (($v - $min) / $span) * ($h - 2 * $pad);
            $coords[] = round($x, 1).','.round($y, 1);
        }

        return implode(' ', $coords);
    };
@endphp

<div class="mx-auto max-w-3xl px-4 py-12">
    <header class="mb-8">
        <div class="flex flex-wrap items-baseline justify-between gap-4">
            <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">{{ Lang::get('drift-alerts::watch.heading') }}</h1>
            <a href="{{ Destination::DriftAlerts->url() }}" class="tap-link text-xs font-medium text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-100">{{ Lang::get('drift-alerts::watch.drift_alerts_link') }}</a>
        </div>
        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
            {{ Lang::get('drift-alerts::watch.intro') }}
        </p>

        @if ($trackedCount > 0)
            {{-- Each figure keeps its own word: wrapping between them left
                 "/month total" alone on a line under "1 tracked", describing
                 nothing. --}}
            <div class="mt-6 flex flex-wrap items-baseline gap-x-3 gap-y-2 rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700 dark:bg-slate-900 dark:border-slate-700 dark:text-slate-300">
                <span class="whitespace-nowrap">
                    <span class="font-medium text-slate-900 dark:text-slate-100" style="font-variant-numeric: tabular-nums;">{{ $trackedCount }}</span>
                    <span class="text-slate-400 dark:text-slate-500" aria-hidden="true">{{ Lang::get('drift-alerts::watch.tracked') }}</span>
                </span>
                <span class="text-slate-300 dark:text-slate-600" aria-hidden="true">·</span>
                <span class="whitespace-nowrap">
                    <span class="font-medium {{ $driftedUpCount > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-slate-900 dark:text-slate-100' }}" style="font-variant-numeric: tabular-nums;">{{ $driftedUpCount }}</span>
                    <span class="text-slate-400 dark:text-slate-500" aria-hidden="true">{{ Lang::get('drift-alerts::watch.crept_up') }}</span>
                </span>
                <span class="text-slate-300 dark:text-slate-600" aria-hidden="true">·</span>
                <span class="whitespace-nowrap">
                    <span style="font-variant-numeric: tabular-nums;">{{ $fmt($monthlyTotal->minor, $monthlyTotal->currency) }}</span>
                    <span class="text-slate-400 dark:text-slate-500" aria-hidden="true">{{ Lang::get('drift-alerts::watch.per_month_total') }}</span>
                </span>
                @if ($monthlyTotal->isPartial())
                    <span class="text-slate-400 dark:text-slate-500" data-not-converted="true">{{ Lang::get('core::money.not_converted', ['list' => $monthlyTotal->unconvertedList()]) }}</span>
                @endif
            </div>
        @endif
    </header>

    @if ($trackedCount === 0)
        <x-core::empty-state :heading="Lang::get('drift-alerts::watch.empty_heading')">
            {{-- A slot, not the :body prop: the Recurring link finishes the
                 sentence, and the prop escapes its value. --}}
            <x-slot:body>
                {{ Lang::get('drift-alerts::watch.empty_body') }}
                <a href="{{ Destination::Recurring->url() }}" class="text-slate-900 underline underline-offset-2 dark:text-slate-100">{{ Lang::get('drift-alerts::watch.empty_link') }}</a>.
            </x-slot:body>
        </x-core::empty-state>
    @else
        <ul class="space-y-2">
            @foreach ($rows as $row)
                @php $dir = $row->direction(); @endphp
                <x-core::card tag="li" padding="tight">
                    <div class="flex items-center justify-between gap-4">
                        <div class="min-w-0 flex-1">
                            <p class="flex items-center gap-2 text-sm">
                                {{-- The clip is on the span, not the link: an
                                     `overflow: hidden` element clips its own
                                     ::after, so the halo that gives this 19px
                                     row title its 44px could not leave the
                                     text it was measuring. --}}
                                <a
                                    href="{{ route('recurring.series.show', ['seriesId' => $row->seriesId]) }}"
                                    class="tap-link min-w-0 font-medium text-slate-900 hover:underline underline-offset-2 dark:text-slate-100"
                                ><span class="block truncate">{{ $row->name }}</span></a>
                                @if ($row->hasOpenAlert)
                                    <a href="{{ Destination::DriftAlerts->url() }}" class="shrink-0 rounded-full px-1.5 py-0.5 text-[11px] font-medium text-amber-700 dark:text-amber-300" style="background: color-mix(in srgb, currentColor 14%, transparent);">{{ Lang::get('drift-alerts::watch.open_alert') }}</a>
                                @endif
                            </p>
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400" style="font-variant-numeric: tabular-nums;">
                                {{ $fmt($row->baselineMinor, $row->currency) }}
                                <span class="text-slate-300 dark:text-slate-600" aria-hidden="true">→</span>
                                {{ $fmt($row->latestMinor, $row->currency) }}
                            </p>
                        </div>

                        {{-- 88px of decoration outranked the name on a phone:
                             the row left "Woonstichting Delta" 81px and drew it
                             as "Woonstich…" beside a flat grey line. The
                             sparkline is aria-hidden, so a phone loses nothing
                             by dropping it. --}}
                        <svg viewBox="0 0 88 26" width="88" height="26" class="hidden shrink-0 text-slate-300 sm:block dark:text-slate-600" aria-hidden="true" preserveAspectRatio="none">
                            <polyline
                                points="{{ $sparkline($row->points) }}"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.5"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            />
                        </svg>

                        <div class="w-24 shrink-0 text-right">
                            <p class="text-sm font-medium {{ $deltaClass[$dir] }}" style="font-variant-numeric: tabular-nums;">
                                {{ $signed($row->deltaMinor, $row->currency) }}
                            </p>
                            <p class="text-xs {{ $deltaClass[$dir] }}" style="font-variant-numeric: tabular-nums;">
                                {{ ($row->deltaPercent >= 0 ? '+' : '−').Fmt::number(round(abs($row->deltaPercent), 1), 1) }}%
                            </p>
                        </div>
                    </div>
                </x-core::card>
            @endforeach
        </ul>
    @endif
</div>
