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
    <header class="flex items-start justify-between gap-6">
        <div class="space-y-1">
            <h1 class="text-3xl font-semibold tracking-tight text-slate-900">{{ $summary->period->label }}</h1>
            <p class="text-sm text-slate-500">This period at a glance.</p>
        </div>
        <div class="flex items-center gap-1">
            <button
                type="button"
                wire:click="previousPeriod"
                aria-label="Previous period"
                class="inline-flex h-10 w-10 items-center justify-center rounded-md text-slate-500 hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
            >&lsaquo;</button>
            <button
                type="button"
                wire:click="today"
                class="inline-flex h-10 items-center rounded-md px-3 text-sm text-slate-500 hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
            >Today</button>
            <button
                type="button"
                wire:click="nextPeriod"
                aria-label="Next period"
                class="inline-flex h-10 w-10 items-center justify-center rounded-md text-slate-500 hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
            >&rsaquo;</button>
        </div>
    </header>

    {{-- Status tile row — "Next ICS settlement" + "Email scan health"
         tiles. Either tile may be hidden when its source query returns
         null; the row collapses to a single tile or vanishes entirely
         depending on which surfaces have data. Side-by-side on desktop
         (md:grid-cols-2), stacked on mobile. --}}
    @if ((isset($nextSettlement) && $nextSettlement !== null) || (isset($emailScanHealth) && $emailScanHealth !== null))
        <section class="grid grid-cols-1 gap-4 md:grid-cols-2" aria-label="Status tiles">
            {{-- "Next ICS settlement" tile (D-99 / D-100, CHN-06).
                 Hides entirely when `$nextSettlement` is null. Border /
                 radius / padding match the existing tile chrome verbatim.
                 Tile amount uses Display 32px semibold tabular-nums in
                 slate-900 (never emerald — emerald is reserved for net-
                 positive KPIs and an outstanding settlement balance is
                 never positive-good per Phase 3 D-46). --}}
            @if (isset($nextSettlement) && $nextSettlement !== null)
                <div class="rounded-lg border border-slate-200 bg-white p-6">
                    <p class="text-base font-semibold text-slate-900">Next ICS settlement</p>
                    <p class="mt-2 text-3xl font-semibold text-slate-900" style="font-variant-numeric: tabular-nums;">
                        {{ $nextSettlement->amount->format('nl_NL') }}
                    </p>
                    <p class="mt-1 text-xs text-slate-500">due ~{{ $nextSettlement->dueDate->format('d M') }}</p>
                </div>
            @endif

            {{-- "Email scan health" tile.
                 Wrapped in an <a> so the whole card is clickable; the inner
                 hover:ring-2 provides the affordance. Hidden entirely when
                 `$emailScanHealth` is null (zero connected inboxes). --}}
            @if (isset($emailScanHealth) && $emailScanHealth !== null)
                <a
                    href="{{ route('inboxes.index') }}"
                    class="block rounded-lg transition hover:ring-2 hover:ring-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
                    aria-label="Email scan health — {{ count($emailScanHealth->lines) + $emailScanHealth->overflowCount }} connected {{ ($emailScanHealth->overflowCount + count($emailScanHealth->lines)) === 1 ? 'inbox' : 'inboxes' }}"
                >
                    @include('email-scan::livewire.email-scan-health-tile', ['tile' => $emailScanHealth])
                </a>
            @endif
        </section>
    @endif

    {{-- KPI tiles: the primary focal point of the dashboard.

         When the user's default_currency_view is 'eur_only', `$tiles` is
         null and a single row of In / Out / Net tiles renders from
         `$summary`. When 'original', `$tiles` is a list of PerCurrencyTile
         values (one per currency present in the period with non-zero
         activity, alphabetical by ISO code); the section stacks one
         labeled tile-row per currency. An EUR-only month in original
         mode collapses to a single labeled row that visually matches the
         EUR-only layout. --}}
    @if ($tiles === null)
        <section class="grid grid-cols-1 gap-4 md:grid-cols-3" aria-label="This period totals">
            <div class="rounded-lg border border-slate-200 bg-white p-6">
                <p class="text-xs uppercase tracking-wide text-slate-500">In</p>
                <p class="mt-2 text-3xl font-semibold text-slate-900" style="font-variant-numeric: tabular-nums;">
                    {{ $fmt($summary->inflow) }}
                </p>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-6">
                <p class="text-xs uppercase tracking-wide text-slate-500">Out</p>
                <p class="mt-2 text-3xl font-semibold text-slate-900" style="font-variant-numeric: tabular-nums;">
                    {{ $fmt($summary->outflow) }}
                </p>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-6">
                <p class="text-xs uppercase tracking-wide text-slate-500">Net</p>
                <p
                    class="mt-2 text-3xl font-semibold {{ $summary->net->isNegative() ? 'text-slate-900' : 'text-emerald-600' }}"
                    style="font-variant-numeric: tabular-nums;"
                >
                    {{ $fmt($summary->net) }}
                </p>
            </div>
        </section>
    @else
        <div class="space-y-12">
            @foreach ($tiles as $tile)
                <section aria-label="This period totals — {{ $tile->currency }}" class="space-y-2">
                    <h2 class="text-xs uppercase tracking-wide text-slate-500">{{ $tile->currency }}</h2>
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                        <div class="rounded-lg border border-slate-200 bg-white p-6">
                            <p class="text-xs uppercase tracking-wide text-slate-500">In</p>
                            <p class="mt-2 text-3xl font-semibold text-slate-900" style="font-variant-numeric: tabular-nums;">
                                {{ $fmt($tile->inflow) }}
                            </p>
                        </div>
                        <div class="rounded-lg border border-slate-200 bg-white p-6">
                            <p class="text-xs uppercase tracking-wide text-slate-500">Out</p>
                            <p class="mt-2 text-3xl font-semibold text-slate-900" style="font-variant-numeric: tabular-nums;">
                                {{ $fmt($tile->outflow) }}
                            </p>
                        </div>
                        <div class="rounded-lg border border-slate-200 bg-white p-6">
                            <p class="text-xs uppercase tracking-wide text-slate-500">Net</p>
                            <p
                                class="mt-2 text-3xl font-semibold {{ $tile->net->isNegative() ? 'text-slate-900' : 'text-emerald-600' }}"
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

    {{-- Inline "Fixed monthly payments" card — top six approved
         recurring series with View-all link to `/recurring`. The card
         resolves through Livewire's container; cross-user scoping
         happens inside FixedPaymentsViewQuery. --}}
    @livewire('recurring.fixed-payments-card')

    {{-- Top spending categories --}}
    <section class="space-y-4">
        <h2 class="text-xl font-semibold text-slate-900">Top spending</h2>
        @if (count($summary->topCategories) === 0)
            <p class="text-sm text-slate-500">No categorized expenses yet.</p>
        @else
            <ul class="space-y-3">
                @foreach ($summary->topCategories as $cat)
                    <li class="space-y-1">
                        <div class="flex items-baseline justify-between text-sm">
                            <span class="text-slate-900">{{ $cat->name }}</span>
                            <span class="text-slate-900" style="font-variant-numeric: tabular-nums;">
                                {{ $fmt($cat->spend) }}
                            </span>
                        </div>
                        <div class="h-1 w-full overflow-hidden rounded-full bg-slate-200">
                            @php
                                $rawPct = (int) round($cat->percentageOfTotal * 100);
                                $barWidth = $rawPct === 0 ? 0 : max(2, min(100, $rawPct));
                            @endphp
                            <div
                                class="h-1 bg-slate-900"
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
            <h2 class="text-xl font-semibold text-slate-900">Recent transactions</h2>
            <a
                href="{{ route('transactions.index') }}"
                class="text-sm text-slate-500 underline underline-offset-2 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
            >View all</a>
        </div>

        @if (count($summary->recentTransactions) === 0)
            <p class="text-sm text-slate-500">Nothing here for this period.</p>
        @else
            <div class="overflow-hidden rounded-lg border border-slate-200">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50">
                        <tr>
                            <th scope="col" class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wide text-slate-500">Date</th>
                            <th scope="col" class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wide text-slate-500">Counterparty</th>
                            <th scope="col" class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wide text-slate-500">Category</th>
                            <th scope="col" class="px-4 py-2 text-right text-xs font-medium uppercase tracking-wide text-slate-500">Amount</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 bg-white">
                        @foreach ($summary->recentTransactions as $row)
                            <tr>
                                <td class="px-4 py-2 text-slate-900" style="font-variant-numeric: tabular-nums;">{{ $row->bookedAt }}</td>
                                <td class="px-4 py-2 text-slate-900">{{ $row->counterpartyName ?? '—' }}</td>
                                <td class="px-4 py-2 text-slate-500">{{ $row->categoryName ?? 'Uncategorized' }}</td>
                                <td class="px-4 py-2 text-right text-slate-900" style="font-variant-numeric: tabular-nums;">
                                    {{ $fmt($row->amount) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    {{-- Reauth-detected toast.

         Persistent (no auto-dismiss) backed by inbox_scan_state.status =
         'needs_reauth' filtered by user_id. Suppressed for the rest of
         the session once the user clicks ×; reappears on next login if
         any inbox is still needs_reauth. Same chrome as the failed-job
         toast below but with its own copy + a distinct surface order
         (this toast renders above the failed-job toast when both are
         visible — see UI-SPEC § Reauth-detected toast). --}}
    @if ($reauthInboxCount > 0 && ! $reauthToastDismissed)
        <div
            role="status"
            aria-live="polite"
            class="fixed bottom-24 right-4 z-50 max-w-sm rounded-lg border-l-2 border-rose-600 bg-white p-4 shadow-md"
        >
            <div class="flex items-start justify-between gap-3">
                <div class="space-y-1">
                    <p class="text-sm font-medium text-slate-900">An inbox needs reconnecting.</p>
                    <p class="text-xs text-slate-500">One or more inboxes were signed out — diederik can't scan them until you reconnect.</p>
                    <a
                        href="{{ route('inboxes.index') }}"
                        class="text-xs text-slate-900 underline-offset-2 hover:underline"
                    >Go to Inboxes</a>
                </div>
                <button
                    type="button"
                    aria-label="Dismiss"
                    wire:click="dismissReauthToast"
                    class="rounded text-slate-500 hover:text-slate-900 focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
                >
                    {{-- Inline Heroicons-outline x-mark 16×16 (no
                         blade-heroicons package is installed in the
                         project; UI-SPEC § Icon usage approves this
                         inline render). --}}
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-4 w-4" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    @endif

    {{-- Failed-job toast (D-103 / issue #1 + #8).

         Persistent toast (no auto-dismiss) backed by the
         `chain_resolution_runs` audit table filtered by exact
         `user_id` match. Replaces an earlier draft's substring
         `payload LIKE '%userId:N%'` query against `failed_jobs`,
         which leaked across users with id prefixes like 1 vs 11.

         Surface order: above the dashboard content, fixed bottom-right
         (`z-50`), with a 2px rose-600 left stripe. The toast hides
         when the audit row is cleared (e.g. user retried in
         `/horizon/failed`). --}}
    @if ($failedChainResolutionExists)
        <div
            role="status"
            aria-live="polite"
            class="fixed bottom-4 right-4 z-50 max-w-sm rounded-lg border-l-2 border-rose-600 bg-white p-4 shadow-md"
        >
            <p class="text-sm font-semibold text-slate-900">Chain resolution failed.</p>
            <p class="mt-1 text-xs text-slate-500">
                One or more chain-resolution jobs hit an error. Open Horizon to retry or inspect.
            </p>
            <a
                href="/horizon/failed"
                class="mt-2 inline-block text-xs font-medium text-slate-900 underline underline-offset-2 hover:text-slate-700"
            >Open Horizon</a>
        </div>
    @endif
</div>
