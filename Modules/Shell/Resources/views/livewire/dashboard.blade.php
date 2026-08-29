@use('Modules\Core\Public\Navigation\Destination')
@use('Modules\Core\Public\Support\Lang')
@php
    use Modules\Ledger\Public\ValueObjects\Money;

    $fmt = static fn (Money $money): string => $money->format();
@endphp

<div
    class="space-y-12"
    x-data
    x-on:keydown.window.left="if (!['INPUT','TEXTAREA','SELECT'].includes(document.activeElement?.tagName)) $wire.previousPeriod()"
    x-on:keydown.window.right="if (!['INPUT','TEXTAREA','SELECT'].includes(document.activeElement?.tagName)) $wire.nextPeriod()"
    x-on:keydown.window.t="if (!['INPUT','TEXTAREA','SELECT'].includes(document.activeElement?.tagName)) $wire.today()"
>
    {{--
        Phone responsive pass (UI-SPEC §9).

        At <768px (phone), the dashboard column switches to a flex container
        with explicit order values so the inbox-like posture is achieved
        without moving or duplicating any markup:

          order-1: header
          order-2: alerts strip (drift badge)
          order-3: KPI tiles (single-column at phone)
          order-4: goals summary card, budgets glance
          order-5: upcoming content (fixed payments, spending trend, savings insights)
          order-6: tax summary, pinned reports, status tiles + net worth (deprioritised on phone)
          order-7: top spending + recent transactions
          order-8: install hint

        EVERY child of .dashboard-main carries an order class. A child without
        one falls back to `order: 0`, which sorts it ABOVE order-1 — that is
        how the tax/budgets/pinned cards ended up over the period summary the
        page exists to show.

        At >=768px (tablet/desktop), all order classes are reset (order-none) and
        the existing space-y-12 block layout is preserved exactly.

        The responsive CSS rules for .dashboard-main and .dashboard-phone-order-*
        live in resources/css/app.css, in the per-page responsive section.
    --}}

    <div class="dashboard-main">

    {{-- Header (order 1 on phone, first naturally on desktop).

         items-baseline, not items-start: the period title and the ‹ Today ›
         stepper are one control line, and aligning their boxes left the
         30px title and the 40px buttons reading off two different lines.
         The row STACKS below sm rather than squeezing, so a long month name
         takes the width it needs. Measured on an iPhone 12 mini: "Αύγουστος
         2026" put the stepper's right edge at 397px on a 375pt screen, taking
         the next-period glyph off the display.

         The stepper keeps shrink-0 and takes flex-wrap beside it. At the
         reader's accessibility text sizes ‹ Today › is 464px of glyphs and
         padding on a 375pt screen; unshrinkable and unwrappable together, the
         only give left was inside the label, which broke into "Tod / ay" in a
         button two lines too short to hold it. Wrapping gives the three
         buttons a second row and their own widths back. --}}
    <header class="flex flex-col gap-4 sm:flex-row sm:items-baseline sm:justify-between sm:gap-6 dashboard-phone-order-1">
        <div class="space-y-1">
            <x-core::page-heading level="section">{{ $summary->period->label }}</x-core::page-heading>
            <p class="text-sm text-slate-500 dark:text-slate-400">{{ Lang::get('core::dashboard.subtitle') }}</p>
        </div>
        <div class="flex shrink-0 flex-wrap items-center gap-1">
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

    {{-- Alerts strip (order 2 on phone): drift alerts, full-width stacked --}}
    {{-- Inline "Drift alerts" tile — count + EUR-roll-up annualized
         impact for open drift_alerts. The tile renders no chrome when
         the user has zero open drift alerts; the dashboard collapses
         gracefully on a quiet day. Cross-user scoping happens inside
         DriftAlertQuery. --}}
    <div class="dashboard-phone-order-2 dashboard-tile">
        @livewire('drift-alerts.dashboard-drift-badge')
    </div>

    {{-- Unusual charges tile: a distinct anomaly indicator,
         separate from the drift tile, hidden when there are zero open
         anomalies. Cross-user scoping happens inside AnomalyAlertQuery. --}}
    <div class="dashboard-phone-order-2 dashboard-tile">
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
            <x-core::card>
                <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('core::dashboard.in') }}</p>
                <p class="mt-2 text-3xl font-semibold text-slate-900 dark:text-slate-100" style="font-variant-numeric: tabular-nums;">
                    {{ $fmt($summary->inflow) }}
                </p>
            </x-core::card>
            <x-core::card>
                <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('core::dashboard.out') }}</p>
                <p class="mt-2 text-3xl font-semibold text-slate-900 dark:text-slate-100" style="font-variant-numeric: tabular-nums;">
                    {{ $fmt($summary->outflow) }}
                </p>
            </x-core::card>
            <x-core::card>
                <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('core::dashboard.net') }}</p>
                <p
                    class="mt-2 text-3xl font-semibold {{ $summary->net->isNegative() ? 'text-slate-900 dark:text-slate-100' : 'text-emerald-700 dark:text-emerald-500' }}"
                    style="font-variant-numeric: tabular-nums;"
                >
                    {{ $fmt($summary->net) }}
                </p>
                @if ($summary->unconvertedCurrencies !== [])
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400" data-not-converted="true">
                        {{ Lang::get('core::money.not_converted', ['list' => implode(', ', $summary->unconvertedCurrencies)]) }}
                    </p>
                @endif
            </x-core::card>
        </section>
    @else
        <div class="space-y-12">
            @foreach ($tiles as $tile)
                <section aria-label="{{ Lang::get('core::dashboard.totals_aria_currency', ['currency' => $tile->currency]) }}" class="space-y-2">
                    <h2 class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ $tile->currency }}</h2>
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                        <x-core::card>
                            <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('core::dashboard.in') }}</p>
                            <p class="mt-2 text-3xl font-semibold text-slate-900 dark:text-slate-100" style="font-variant-numeric: tabular-nums;">
                                {{ $fmt($tile->inflow) }}
                            </p>
                        </x-core::card>
                        <x-core::card>
                            <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('core::dashboard.out') }}</p>
                            <p class="mt-2 text-3xl font-semibold text-slate-900 dark:text-slate-100" style="font-variant-numeric: tabular-nums;">
                                {{ $fmt($tile->outflow) }}
                            </p>
                        </x-core::card>
                        <x-core::card>
                            <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('core::dashboard.net') }}</p>
                            <p
                                class="mt-2 text-3xl font-semibold {{ $tile->net->isNegative() ? 'text-slate-900 dark:text-slate-100' : 'text-emerald-700 dark:text-emerald-500' }}"
                                style="font-variant-numeric: tabular-nums;"
                            >
                                {{ $fmt($tile->net) }}
                            </p>
                        </x-core::card>
                    </div>
                </section>
            @endforeach
        </div>
    @endif
    </div>

    {{-- Goals summary card (order 4 on phone) — up to 3 nearest-finishing active goals.
         Renders a calm empty-state when the user has no goals. --}}
    <div class="dashboard-phone-order-4 dashboard-tile">
        @livewire('goals.summary-card')
    </div>

    {{-- Tax summary card — tagged total + item count for the seasonal
         tax year (Jan-Apr → previous year; May-Dec → current year). Links to /tax.
         Seasonal, so it joins the order-6 tail on phones. --}}
    <div class="dashboard-phone-order-6 dashboard-tile">
        @livewire('tax.summary-card')
    </div>

    {{-- Budgets glance card — "Ready to assign" figure sourced
         from the envelope model, plus an amber over-budget pill. Renders
         nothing when the user has zero expense categories. Sits with the
         goals card (order 4): both answer "am I on plan this period?". --}}
    <div class="dashboard-phone-order-4 dashboard-tile">
        @livewire('budgets.envelope-glance-card')
    </div>

    {{-- Pinned reports — up to 3 chart-only mini cards from saved
         reports the user pinned via TogglePin (/reports/library). Renders
         nothing when zero pins, same convention as goals.summary-card /
         tax.summary-card / budgets.envelope-glance-card above. --}}
    <div class="dashboard-phone-order-6 dashboard-tile">
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
             The older inline "Next ICS settlement" tile is replaced by the
             Forecast highlights Livewire tile, a strict superset —
             the next-settlement line is preserved. Either tile may be
             hidden when its source query returns no data. Side-by-side on
             desktop (md:grid-cols-2), stacked on mobile. --}}
        <section class="grid grid-cols-1 gap-4 md:grid-cols-2" aria-label="{{ Lang::get('core::dashboard.status_tiles_aria') }}">
            @livewire('forecasting.forecast-highlights-tile')

            @if (isset($emailScanHealth) && $emailScanHealth !== null)
                @php
                    $emailScanCount = count($emailScanHealth->lines) + $emailScanHealth->overflowCount;
                @endphp
                <a
                    href="{{ Destination::Email->url() }}"
                    class="block rounded-lg transition hover:ring-2 hover:ring-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
                    aria-label="{{ Lang::choice('core::dashboard.email_scan_health', $emailScanCount) }}"
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
                            {{-- The name truncates and the amount holds its width:
                                 without this a long category pushed the figure
                                 straight off the right edge of a phone. --}}
                            <div class="flex flex-wrap items-baseline justify-between gap-2 text-sm">
                                <span class="min-w-0 truncate text-slate-900 dark:text-slate-100">{{ $cat->name }}</span>
                                <span class="shrink-0 text-slate-900 dark:text-slate-100" style="font-variant-numeric: tabular-nums;">
                                    {{ $fmt($cat->spend) }}
                                </span>
                            </div>
                            {{-- The sliver stays a call-site rule: a category with
                                 a real but tiny share should still draw, while a
                                 zero draws nothing at all. --}}
                            @php
                                $rawPct = (int) round($cat->percentageOfTotal * 100);
                                $barWidth = $rawPct === 0 ? 0 : max(2, min(100, $rawPct));
                            @endphp
                            <x-core::progress-bar :value="$barWidth" :label="$cat->name" />
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- Recent transactions --}}
        <section class="space-y-4">
            <div class="flex flex-wrap items-center justify-between">
                <h2 class="text-xl font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('core::dashboard.recent_transactions') }}</h2>
                <a
                    href="{{ Destination::Transactions->url() }}"
                    class="tap-link text-sm text-slate-500 underline underline-offset-2 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:hover:text-slate-100 dark:text-slate-400"
                >{{ Lang::get('core::dashboard.view_all') }}</a>
            </div>

            @if (count($summary->recentTransactions) === 0)
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ Lang::get('core::dashboard.nothing_period') }}</p>
            @else
                {{-- Scrolling alone was not enough here. With real data on a phone
                     the table opened on DATUM / TEGENPARTIJ / CATEGORIE and BEDRAG
                     sat past the right edge — the one figure the row exists to
                     show, reachable only by swiping a table nothing marks as
                     swipeable. `dash-recent-table` restacks each row at phone width
                     so the amount is on screen without any gesture. --}}
                <x-core::data-table class="dash-recent-table">
                    <x-slot:head>
                        <x-core::th align="left">{{ Lang::get('core::dashboard.th_date') }}</x-core::th>
                        <x-core::th align="left">{{ Lang::get('core::dashboard.th_counterparty') }}</x-core::th>
                        <x-core::th align="left">{{ Lang::get('core::dashboard.th_category') }}</x-core::th>
                        <x-core::th align="right">{{ Lang::get('core::dashboard.th_amount') }}</x-core::th>
                    </x-slot:head>

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
                </x-core::data-table>
            @endif
        </section>
    </div>

    {{-- Standing install-hint card (order 8 on phone, bottom of column on all viewports) --}}
    {{-- "Also want to see your data on your phone?" standing promo card.
         The install-hint component owns the copy and the
         beforeinstallprompt / iOS fallback logic. --}}
    {{-- The order class is the component's own, not a wrapper's: Alpine hides
         the root with display:none, and a wrapper around it would stay a flex
         item and keep its gap. --}}
    <x-core::install-hint class="dashboard-phone-order-8" />

    </div>{{-- end .dashboard-main --}}

    {{-- Reauth-detected toast.

         Persistent (no auto-dismiss) backed by inbox_scan_state.status =
         'needs_reauth' filtered by user_id. Suppressed for the rest of
         the session once the user dismisses it; reappears on next login if
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
                        href="{{ Destination::Email->url() }}"
                        class="text-xs text-slate-900 underline-offset-2 hover:underline dark:text-slate-100"
                    >{{ Lang::get('core::dashboard.reauth.link') }}</a>
                </div>
                <x-core::emoji-action
                    :label="Lang::get('core::dashboard.reauth.dismiss')"
                    wire:click="dismissReauthToast"
                >✖️</x-core::emoji-action>
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
