@use('Modules\Core\Public\Support\Lang')
@php
    use Modules\Ledger\Public\ValueObjects\Money;

    /**
     * EUR amounts render in Dutch locale (e.g. `€ 68,86`); non-EUR amounts
     * render in US English locale (e.g. `$74.43`) so the symbol prefix
     * matches the user's mental model of the foreign currency. brick/money
     * routes the locale through ext-intl's NumberFormatter; the parameter-
     * less Money::format() default already encodes this routing — the
     * explicit branch here documents the intent at the call site.
     */
    $fmt = static fn (Money $money): string => $money->currency() === 'EUR'
        ? $money->format('nl_NL')
        : $money->format('en_US');
@endphp

<div
    class="space-y-12"
    x-data
    x-on:keydown.window.left="if (!['INPUT','TEXTAREA','SELECT'].includes(document.activeElement?.tagName)) $wire.previousPeriod()"
    x-on:keydown.window.right="if (!['INPUT','TEXTAREA','SELECT'].includes(document.activeElement?.tagName)) $wire.nextPeriod()"
    x-on:keydown.window.t="if (!['INPUT','TEXTAREA','SELECT'].includes(document.activeElement?.tagName)) $wire.today()"
    wire:poll.5s="refreshFailedChainResolution"
>
    {{--
        Phone responsive pass (D-15, UI-SPEC §9).

        At <768px (phone), the dashboard column switches to a flex container
        with explicit order values so the D-15 inbox-like posture is achieved
        without moving or duplicating any markup:

          order-1: header
          order-2: alerts strip (drift badge)
          order-3: KPI tiles (single-column at phone)
          order-4: goals summary card
          order-5: upcoming content (fixed payments, spending trend, savings insights)
          order-6: status tiles + net worth (deprioritised on phone)
          order-7: top spending + recent transactions
          order-8: install hint

        At >=768px (tablet/desktop), all order classes are reset (order-none) and
        the existing space-y-12 block layout is preserved exactly.

        The responsive CSS rules for .dashboard-main and .dashboard-phone-order-*
        live in resources/css/app.css (Phase 4 per-page responsive section).
    --}}

    <div class="dashboard-main">

    {{-- Header (order 1 on phone, first naturally on desktop) --}}
    <header class="flex items-start justify-between gap-6 dashboard-phone-order-1">
        <div class="space-y-1">
            <h1 class="text-3xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">{{ $summary->period->label }}</h1>
            <p class="text-sm text-slate-500 dark:text-slate-400">{{ Lang::get('core::dashboard.subtitle') }}</p>
        </div>
        <div class="flex items-center gap-1">
            <button
                type="button"
                wire:click="previousPeriod"
                aria-label="{{ Lang::get('core::dashboard.previous_period') }}"
                class="inline-flex h-10 w-10 items-center justify-center rounded-md text-slate-500 hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:hover:bg-slate-800 dark:hover:text-slate-100 dark:text-slate-400"
            >&lsaquo;</button>
            <button
                type="button"
                wire:click="today"
                class="inline-flex h-10 items-center rounded-md px-3 text-sm text-slate-500 hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:hover:bg-slate-800 dark:hover:text-slate-100 dark:text-slate-400"
            >{{ Lang::get('core::dashboard.today') }}</button>
            <button
                type="button"
                wire:click="nextPeriod"
                aria-label="{{ Lang::get('core::dashboard.next_period') }}"
                class="inline-flex h-10 w-10 items-center justify-center rounded-md text-slate-500 hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:hover:bg-slate-800 dark:hover:text-slate-100 dark:text-slate-400"
            >&rsaquo;</button>
        </div>
    </header>

    {{-- Alerts strip (order 2 on phone): drift alerts, full-width stacked (D-15) --}}
    {{-- Inline "Drift alerts" tile — count + EUR-roll-up annualized
         impact for open drift_alerts. The tile renders no chrome when
         the user has zero open drift alerts; the dashboard collapses
         gracefully on a quiet day. Cross-user scoping happens inside
         DriftAlertQuery. --}}
    <div class="dashboard-phone-order-2">
        @livewire('drift-alerts.dashboard-drift-badge')
    </div>

    {{-- Unusual charges tile (D-03): a distinct anomaly indicator,
         separate from the drift tile, hidden when there are zero open
         anomalies. Cross-user scoping happens inside AnomalyAlertQuery. --}}
    <div class="dashboard-phone-order-2">
        @livewire('anomaly.dashboard-anomaly-badge')
    </div>

    {{-- KPI tiles (order 3 on phone): the primary focal point of the dashboard.
         Single-column at <768px (overriding the md:grid-cols-3 grid), desktop
         3-up grid unchanged at >=768px.

         When the user's default_currency_view is 'eur_only', `$tiles` is
         null and a single row of In / Out / Net tiles renders from
         `$summary`. When 'original', `$tiles` is a list of PerCurrencyTile
         values (one per currency present in the period with non-zero
         activity, alphabetical by ISO code); the section stacks one
         labeled tile-row per currency. An EUR-only month in original
         mode collapses to a single labeled row that visually matches the
         EUR-only layout. --}}
    <div class="dashboard-phone-order-3">
    @if ($tiles === null)
        <section class="grid grid-cols-1 gap-4 md:grid-cols-3" aria-label="{{ Lang::get('core::dashboard.totals_aria') }}">
            <div class="rounded-lg border border-slate-200 bg-white p-6 dark:bg-slate-950 dark:border-slate-700">
                <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('core::dashboard.in') }}</p>
                <p class="mt-2 text-3xl font-semibold text-slate-900 dark:text-slate-100" style="font-variant-numeric: tabular-nums;">
                    {{ $fmt($summary->inflow) }}
                </p>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-6 dark:bg-slate-950 dark:border-slate-700">
                <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('core::dashboard.out') }}</p>
                <p class="mt-2 text-3xl font-semibold text-slate-900 dark:text-slate-100" style="font-variant-numeric: tabular-nums;">
                    {{ $fmt($summary->outflow) }}
                </p>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-6 dark:bg-slate-950 dark:border-slate-700">
                <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('core::dashboard.net') }}</p>
                <p
                    class="mt-2 text-3xl font-semibold {{ $summary->net->isNegative() ? 'text-slate-900 dark:text-slate-100' : 'text-emerald-600 dark:text-emerald-500' }}"
                    style="font-variant-numeric: tabular-nums;"
                >
                    {{ $fmt($summary->net) }}
                </p>
            </div>
        </section>
    @else
        <div class="space-y-12">
            @foreach ($tiles as $tile)
                <section aria-label="{{ Lang::get('core::dashboard.totals_aria_currency', ['currency' => $tile->currency]) }}" class="space-y-2">
                    <h2 class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ $tile->currency }}</h2>
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                        <div class="rounded-lg border border-slate-200 bg-white p-6 dark:bg-slate-950 dark:border-slate-700">
                            <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('core::dashboard.in') }}</p>
                            <p class="mt-2 text-3xl font-semibold text-slate-900 dark:text-slate-100" style="font-variant-numeric: tabular-nums;">
                                {{ $fmt($tile->inflow) }}
                            </p>
                        </div>
                        <div class="rounded-lg border border-slate-200 bg-white p-6 dark:bg-slate-950 dark:border-slate-700">
                            <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('core::dashboard.out') }}</p>
                            <p class="mt-2 text-3xl font-semibold text-slate-900 dark:text-slate-100" style="font-variant-numeric: tabular-nums;">
                                {{ $fmt($tile->outflow) }}
                            </p>
                        </div>
                        <div class="rounded-lg border border-slate-200 bg-white p-6 dark:bg-slate-950 dark:border-slate-700">
                            <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('core::dashboard.net') }}</p>
                            <p
                                class="mt-2 text-3xl font-semibold {{ $tile->net->isNegative() ? 'text-slate-900 dark:text-slate-100' : 'text-emerald-600 dark:text-emerald-500' }}"
                                style="font-variant-numeric: tabular-nums;"
                            >
                                {{ $fmt($tile->net) }}
                            </p>
                        </div>
                    </div>
                </section>
            @endforeach
        </div>
    @endif
    </div>

    {{-- Goals summary card (order 4 on phone) — up to 3 nearest-finishing active goals.
         Renders a calm empty-state when the user has no goals. --}}
    <div class="dashboard-phone-order-4">
        @livewire('goals.summary-card')
    </div>

    {{-- Tax summary card (D-18) — tagged total + item count for the seasonal
         tax year (Jan-Apr → previous year; May-Dec → current year). Links to /tax. --}}
    <div>
        @livewire('tax.summary-card')
    </div>

    {{-- Budgets glance card (Req 12, D-21) — "Ready to assign" figure sourced
         from the envelope model, plus an amber over-budget pill. Renders
         nothing when the user has zero expense categories. --}}
    <div>
        @livewire('budgets.envelope-glance-card')
    </div>

    {{-- Pinned reports (Req 10) — up to 3 chart-only mini cards from saved
         reports the user pinned via TogglePin (/reports/library). Renders
         nothing when zero pins, same convention as goals.summary-card /
         tax.summary-card / budgets.envelope-glance-card above. --}}
    <div>
        @livewire('reports.pinned-reports-row')
    </div>

    {{-- Upcoming content (order 5 on phone): fixed payments, spending trend, savings insights --}}
    <div class="dashboard-phone-order-5 space-y-12">
        {{-- Inline "Fixed monthly payments" card — top six approved
             recurring series with View-all link to `/recurring`. The card
             resolves through Livewire's container; cross-user scoping
             happens inside FixedPaymentsViewQuery. --}}
        @livewire('recurring.fixed-payments-card')

        {{-- "This month vs last" — month-over-month spend comparison + top category
             movers. Renders nothing until there is a prior period to compare. --}}
        @livewire('core.spending-trend-card')

        {{-- "Ways to save" — corpus cancel/cheaper links surfaced from recurring
             + drift data. Renders nothing when there is nothing to suggest. --}}
        @livewire('drift-alerts.savings-insights-card')
    </div>

    {{-- Status tiles + net worth (order 6 on phone): deprioritised below core content --}}
    <div class="dashboard-phone-order-6 space-y-12">
        {{-- Status tile row — Forecast highlights + Email scan health.
             Phase 5's "Next ICS settlement" inline tile is REPLACED by the
             Phase 10 Forecast highlights Livewire tile (strict superset —
             the next-settlement line is preserved). Either tile may be
             hidden when its source query returns no data. Side-by-side on
             desktop (md:grid-cols-2), stacked on mobile. --}}
        <section class="grid grid-cols-1 gap-4 md:grid-cols-2" aria-label="{{ Lang::get('core::dashboard.status_tiles_aria') }}">
            @livewire('forecasting.forecast-highlights-tile')

            @if (isset($emailScanHealth) && $emailScanHealth !== null)
                @php
                    $emailScanCount = count($emailScanHealth->lines) + $emailScanHealth->overflowCount;
                @endphp
                <a
                    href="{{ route('inboxes.index') }}"
                    class="block rounded-lg transition hover:ring-2 hover:ring-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
                    aria-label="{{ Lang::get('core::dashboard.email_scan_health', ['count' => $emailScanCount]) }} {{ Lang::choice('core::dashboard.inbox', $emailScanCount) }}"
                >
                    @include('email-scan::livewire.email-scan-health-tile', ['tile' => $emailScanHealth])
                </a>
            @endif
        </section>

        {{-- Net worth — one figure across all accounts (assets minus liabilities),
             with an expandable per-account breakdown. Renders nothing with no
             accounts. --}}
        @livewire('core.net-worth-card')
    </div>

    {{-- Top spending + recent transactions (order 7 on phone) --}}
    <div class="dashboard-phone-order-7 space-y-12">
        {{-- Top spending categories --}}
        <section class="space-y-4">
            <h2 class="text-xl font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('core::dashboard.top_spending') }}</h2>
            @if (count($summary->topCategories) === 0)
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ Lang::get('core::dashboard.no_expenses') }}</p>
            @else
                <ul class="space-y-3">
                    @foreach ($summary->topCategories as $cat)
                        <li class="space-y-1">
                            <div class="flex items-baseline justify-between text-sm">
                                <span class="text-slate-900 dark:text-slate-100">{{ $cat->name }}</span>
                                <span class="text-slate-900 dark:text-slate-100" style="font-variant-numeric: tabular-nums;">
                                    {{ $fmt($cat->spend) }}
                                </span>
                            </div>
                            <div class="h-1 w-full overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700">
                                @php
                                    $rawPct = (int) round($cat->percentageOfTotal * 100);
                                    $barWidth = $rawPct === 0 ? 0 : max(2, min(100, $rawPct));
                                @endphp
                                <div
                                    class="h-1 bg-slate-900 dark:bg-slate-100"
                                    style="width: {{ $barWidth }}%;"
                                ></div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- Recent transactions --}}
        <section class="space-y-4">
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('core::dashboard.recent_transactions') }}</h2>
                <a
                    href="{{ route('transactions.index') }}"
                    class="text-sm text-slate-500 underline underline-offset-2 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:hover:text-slate-100 dark:text-slate-400"
                >{{ Lang::get('core::dashboard.view_all') }}</a>
            </div>

            @if (count($summary->recentTransactions) === 0)
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ Lang::get('core::dashboard.nothing_period') }}</p>
            @else
                <div class="overflow-hidden rounded-lg border border-slate-200 dark:border-slate-700">
                    <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-700">
                        <thead class="bg-slate-50 dark:bg-slate-900">
                            <tr>
                                <th scope="col" class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('core::dashboard.th_date') }}</th>
                                <th scope="col" class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('core::dashboard.th_counterparty') }}</th>
                                <th scope="col" class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('core::dashboard.th_category') }}</th>
                                <th scope="col" class="px-4 py-2 text-right text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('core::dashboard.th_amount') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 bg-white dark:bg-slate-950 dark:divide-slate-700">
                            @foreach ($summary->recentTransactions as $row)
                                <tr>
                                    <td class="px-4 py-2 text-slate-900 dark:text-slate-100" style="font-variant-numeric: tabular-nums;">{{ $row->bookedAt }}</td>
                                    <td class="px-4 py-2 text-slate-900 dark:text-slate-100">{{ $row->counterpartyName ?? '—' }}</td>
                                    <td class="px-4 py-2 text-slate-500 dark:text-slate-400">{{ $row->categoryName ?? Lang::get('core::dashboard.uncategorized') }}</td>
                                    <td class="px-4 py-2 text-right text-slate-900 dark:text-slate-100" style="font-variant-numeric: tabular-nums;">
                                        {{ $fmt($row->amount) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>

    {{-- Standing install-hint card (order 8 on phone, bottom of column on all viewports) --}}
    {{-- D-22: "Also want to see your data on your phone?" standing promo card.
         The install-hint component (plan 03) owns the copy and the
         beforeinstallprompt / iOS fallback logic. --}}
    <div class="dashboard-phone-order-8">
        <x-core::install-hint />
    </div>

    </div>{{-- end .dashboard-main --}}

    {{-- Reauth-detected toast.

         Persistent (no auto-dismiss) backed by inbox_scan_state.status =
         'needs_reauth' filtered by user_id. Suppressed for the rest of
         the session once the user clicks ×; reappears on next login if
         any inbox is still needs_reauth. Same chrome as the failed-job
         toast below but with its own copy + a distinct surface order
         (this toast renders above the failed-job toast when both are
         visible). --}}
    @if ($reauthInboxCount > 0 && ! $reauthToastDismissed)
        <div
            aria-atomic="true"
            aria-live="polite"
            class="fixed bottom-24 right-4 z-50 max-w-sm rounded-lg border-l-2 border-rose-600 bg-white p-4 shadow-md dark:bg-slate-950 dark:border-rose-500"
        >
            <div class="flex items-start justify-between gap-3">
                <div class="space-y-1">
                    <p class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ Lang::get('core::dashboard.reauth.title') }}</p>
                    {{-- {!! !!}: app-static copy whose apostrophe ("can't") is
                         asserted raw by EmailScan's InvalidGrantToastTest;
                         {{ }} would escape it to &#039;. No user data flows here. --}}
                    <p class="text-xs text-slate-500 dark:text-slate-400">{!! Lang::get('core::dashboard.reauth.body') !!}</p>
                    <a
                        href="{{ route('inboxes.index') }}"
                        class="text-xs text-slate-900 underline-offset-2 hover:underline dark:text-slate-100"
                    >{{ Lang::get('core::dashboard.reauth.link') }}</a>
                </div>
                <button
                    type="button"
                    aria-label="{{ Lang::get('core::dashboard.reauth.dismiss') }}"
                    wire:click="dismissReauthToast"
                    class="rounded text-slate-500 hover:text-slate-900 focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:hover:text-slate-100 dark:text-slate-400"
                >
                    {{-- Inline Heroicons-outline x-mark 16×16. No
                         blade-heroicons package is installed in the
                         project; the icon is rendered inline. --}}
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-4 w-4" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    @endif

    {{-- Failed-job toast. Persistent (no auto-dismiss) backed by
         the `chain_resolution_runs` audit table filtered by exact
         user_id match. Gated on $isDeveloper: non-developers see
         nothing here — their channel is the existing
         SystemAlertsBanner. The deep-link target is
         route('dev.queue.tab', ['tab' => 'failed']) — never a
         hardcoded /dev/queue/failed literal and never a
         route('dev.queue.failed') call (no such named route
         exists). The toast hides when the audit row is cleared
         (e.g. the developer retried via the queue inspector). --}}
    @if ($failedChainResolutionExists && $isDeveloper)
        <div
            aria-atomic="true"
            aria-live="polite"
            class="fixed bottom-4 right-4 z-50 max-w-sm rounded-lg border-l-2 border-rose-600 bg-white p-4 shadow-md dark:bg-slate-950 dark:border-rose-500"
        >
            <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('core::dashboard.failed_chain.title') }}</p>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                {{ Lang::get('core::dashboard.failed_chain.body') }}
            </p>
            <a
                href="{{ route('dev.queue.tab', ['tab' => 'failed']) }}"
                class="mt-2 inline-block text-xs font-medium text-slate-900 underline underline-offset-2 hover:text-slate-700 dark:hover:text-slate-300 dark:text-slate-100"
            >{{ Lang::get('core::dashboard.failed_chain.link') }}</a>
        </div>
    @endif
</div>
