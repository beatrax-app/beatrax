{{--
    /tax year cockpit — TAX-02 / D-09.

    $data:           TaxYearData|null  — grouped categories + totals for the selected year
    $availableYears: array<int>        — years with tags (for year switcher options)
    $hasTaxCountry:  bool              — false = first-visit guided empty state

    Page structure (UI-SPEC Section 8):
      [Page header: H1 + year switcher + export buttons]
      [Year-totals strip: deductions | income | item count]
      [Category sections (one per category + "No category" last)]
      [Empty states (Section 9)]

    Phone responsive: card-per-row inside sections; stacked export buttons (Section 17).
    Accessibility: aria-expanded, aria-busy, aria-label on all icon-only buttons (Section 16).
--}}
@use('Modules\Ledger\Public\ValueObjects\Money')
@use('Modules\Ledger\Public\Services\BaseCurrency')

@php
    /**
     * Format a minor (cents) integer as EUR with nl_NL formatting.
     * Uses integer-only arithmetic — no float division.
     */
    $fmtEur = static function (int $minor): string {
        $negative = $minor < 0;
        $abs = abs($minor);
        $euros = intdiv($abs, Money::MINOR_UNITS_PER_MAJOR);
        $cents = $abs % Money::MINOR_UNITS_PER_MAJOR;
        $formatted = '€ ' . number_format($euros, 0, ',', '.') . ',' . str_pad((string) $cents, 2, '0', STR_PAD_LEFT);
        return $negative ? '−' . $formatted : $formatted;
    };
@endphp

<div class="py-12">
    <div class="mx-auto max-w-4xl px-4 sm:px-6">

        {{-- ────────────────────────────────────────────────────────────────── --}}
        {{-- Page header                                                        --}}
        {{-- ────────────────────────────────────────────────────────────────── --}}

        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <h1 style="font-size: var(--text-2xl); font-weight: 600; color: var(--color-text);">
                Tax {{ $year }}
            </h1>

            <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                {{-- Year switcher — wire:model.live updates results immediately --}}
                @if (count($availableYears) > 0)
                    <div class="flex flex-wrap gap-1" role="group" aria-label="Select tax year">
                        @php
                            // Render ALL available years — history is retained forever
                            // (project constraint); the container flex-wraps past one
                            // row, so a hard cap would silently hide older years (IN-05).
                            $years = array_unique(array_merge([$year], $availableYears));
                            rsort($years);
                        @endphp
                        @foreach ($years as $y)
                            <button
                                wire:click="$set('year', {{ $y }})"
                                class="rounded-md px-3 py-1 text-sm font-medium transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 {{ $y === $year ? 'bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900' : 'border border-slate-200 bg-white text-slate-500 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-400 dark:hover:bg-slate-800' }}"
                                aria-pressed="{{ $y === $year ? 'true' : 'false' }}"
                                aria-label="Show tax year {{ $y }}"
                            >{{ $y }}</button>
                        @endforeach
                    </div>
                @endif

                {{-- Export buttons — stream-download, with aria-busy while in flight --}}
                <div class="flex gap-2 sm:ml-2">
                    <button
                        wire:click="exportCsv"
                        wire:loading.attr="aria-busy"
                        wire:loading.attr="disabled"
                        wire:target="exportCsv"
                        class="tax-export-btn"
                        aria-label="Export CSV"
                        title="Download beatrax-tax-{{ $year }}.csv"
                    >
                        <span aria-hidden="true" wire:loading.remove wire:target="exportCsv">↓</span>
                        <span aria-hidden="true" wire:loading wire:target="exportCsv">…</span>
                        Export CSV
                    </button>
                    <button
                        wire:click="exportPdf"
                        wire:loading.attr="aria-busy"
                        wire:loading.attr="disabled"
                        wire:target="exportPdf"
                        class="tax-export-btn"
                        aria-label="Export PDF"
                        title="Download beatrax-tax-{{ $year }}.pdf"
                    >
                        <span aria-hidden="true" wire:loading.remove wire:target="exportPdf">↓</span>
                        <span aria-hidden="true" wire:loading wire:target="exportPdf">…</span>
                        Export PDF
                    </button>
                </div>
            </div>
        </div>

        {{-- ────────────────────────────────────────────────────────────────── --}}
        {{-- First-visit empty state (no tax country set)                       --}}
        {{-- UI-SPEC Section 9 — country not yet set                            --}}
        {{-- ────────────────────────────────────────────────────────────────── --}}

        @if (! $hasTaxCountry)
            <div class="mx-auto max-w-md">
                <div class="card p-8 text-center" style="border-radius: var(--radius-xl); box-shadow: var(--shadow-md);">
                    <h2 style="font-size: var(--text-xl); font-weight: 600; color: var(--color-text); margin-bottom: var(--space-4);">
                        Which country do you file taxes in?
                    </h2>
                    <p style="font-size: var(--text-base); color: var(--color-text-muted); margin-bottom: var(--space-6);">
                        You can change this in Settings → Tax at any time.
                    </p>
                    <a
                        href="{{ route('settings') }}#tax"
                        class="pill-btn-primary"
                        style="display: inline-block; text-decoration: none;"
                    >Set up tax categories</a>
                </div>
            </div>
        @elseif ($data !== null)

        {{-- ────────────────────────────────────────────────────────────────── --}}
        {{-- Year-totals strip (D-11/D-12)                                      --}}
        {{-- ────────────────────────────────────────────────────────────────── --}}

            <div class="tax-totals-strip mb-6">
                <div class="flex flex-col">
                    <span style="font-size: var(--text-xs); color: var(--color-text-faint); text-transform: uppercase; letter-spacing: 0.06em; font-weight: 600;">Total deductions</span>
                    <span class="kpi-number" style="font-size: var(--text-xl); font-weight: 600; color: var(--color-text);">
                        {{ $fmtEur($data->deductionsTotalMinor) }}
                    </span>
                </div>

                @if ($data->incomeTotalMinor > 0)
                    <div class="flex flex-col">
                        <span style="font-size: var(--text-xs); color: var(--color-text-faint); text-transform: uppercase; letter-spacing: 0.06em; font-weight: 600;">Tax-relevant income</span>
                        <span class="kpi-number" style="font-size: var(--text-xl); font-weight: 600; color: var(--color-emerald);">
                            {{ $fmtEur($data->incomeTotalMinor) }}
                        </span>
                    </div>
                @endif

                <div class="flex flex-col">
                    <span style="font-size: var(--text-xs); color: var(--color-text-faint); text-transform: uppercase; letter-spacing: 0.06em; font-weight: 600;">Tagged items</span>
                    <span class="kpi-number" style="font-size: var(--text-xl); font-weight: 600; color: var(--color-text-muted);">
                        {{ $data->itemCount }}
                    </span>
                </div>
            </div>

            {{-- ──────────────────────────────────────────────────────────────── --}}
            {{-- Empty-year state (UI-SPEC Section 9 — empty year view)          --}}
            {{-- ──────────────────────────────────────────────────────────────── --}}

            @if ($data->itemCount === 0)
                <div class="py-12 text-center">
                    <p style="font-size: var(--text-base); color: var(--color-text-muted);">
                        Nothing tagged for {{ $year }} yet.
                    </p>
                    @php
                        $otherYearsCount = array_sum(
                            array_map(
                                static fn (int $y): int => $y !== $year ? 1 : 0,
                                $availableYears,
                            )
                        );
                    @endphp
                    @if ($otherYearsCount > 0)
                        <p style="font-size: var(--text-base); color: var(--color-text-faint); margin-top: var(--space-2);">
                            You have tagged items in other years.
                        </p>
                    @else
                        <p style="font-size: var(--text-base); color: var(--color-text-faint); margin-top: var(--space-2);">
                            Look for the “Tag” button on any transaction row to start tagging.
                        </p>
                    @endif
                    <a
                        href="{{ route('transactions.index') }}"
                        style="display: inline-block; margin-top: var(--space-4); font-size: var(--text-base); color: var(--color-blue); text-decoration: underline;"
                    >Go to Transactions to start tagging →</a>
                </div>

            @else

                {{-- ──────────────────────────────────────────────────────────── --}}
                {{-- Category sections (D-11 — one per category + "No cat" last) --}}
                {{-- ──────────────────────────────────────────────────────────── --}}

                <div class="space-y-4">
                    @foreach ($data->categories as $category)
                        @php
                            $catId    = $category['id'];
                            $catName  = $category['name'] ?? null;
                            $rows     = $category['rows'] ?? [];
                            $subtotal = $category['subtotalMinor'] ?? 0;
                            $count    = count($rows);
                            $isNoCategory = $catId === null;
                            $sectionKey = $isNoCategory ? 'no-cat' : 'cat-' . $catId;
                        @endphp

                        <details
                            class="tax-section"
                            @if (! $isNoCategory) open @endif
                        >
                            {{-- Section header (UI-SPEC §8 — category name + count + subtotal + chevron) --}}
                            <summary
                                class="tax-section-header cursor-pointer list-none focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500"
                                aria-controls="tax-section-body-{{ $sectionKey }}"
                            >
                                <div class="flex min-w-0 flex-1 items-center gap-3">
                                    <span style="font-size: var(--text-base); font-weight: 600; color: {{ $isNoCategory ? 'var(--color-text-faint)' : 'var(--color-text)' }};">
                                        {{ $isNoCategory ? 'No category' : $catName }}
                                    </span>
                                    <span
                                        style="display: inline-flex; align-items: center; padding: 1px 8px; border-radius: var(--radius-full); background: var(--color-surface); border: 1px solid var(--color-border); font-size: var(--text-xs); color: var(--color-text-muted);"
                                        aria-label="{{ $count }} items"
                                    >{{ $count }}</span>
                                </div>
                                <span class="kpi-number" style="font-size: var(--text-base); font-weight: 600; color: var(--color-text); margin-right: var(--space-3);">
                                    {{ $fmtEur($subtotal) }}
                                </span>
                                {{-- Chevron (CSS rotates when open) --}}
                                <span aria-hidden="true" style="color: var(--color-text-faint); font-size: var(--text-xs);">▾</span>
                            </summary>

                            {{-- Transaction table body --}}
                            <div id="tax-section-body-{{ $sectionKey }}">
                                {{-- Desktop: full table --}}
                                <table
                                    class="hidden w-full sm:table"
                                    style="border-collapse: collapse; font-size: var(--text-base);"
                                >
                                    <thead>
                                        <tr style="background: var(--color-surface-2); border-bottom: 1px solid var(--color-border);">
                                            <th class="date px-3 py-2 text-left" style="font-size: var(--text-xs); font-weight: 600; color: var(--color-text-faint); text-transform: uppercase; letter-spacing: 0.04em; width: 7rem;">Date</th>
                                            <th class="px-3 py-2 text-left" style="font-size: var(--text-xs); font-weight: 600; color: var(--color-text-faint); text-transform: uppercase; letter-spacing: 0.04em; width: 6rem;">Account</th>
                                            <th class="px-3 py-2 text-left" style="font-size: var(--text-xs); font-weight: 600; color: var(--color-text-faint); text-transform: uppercase; letter-spacing: 0.04em;">Counterparty</th>
                                            <th class="px-3 py-2 text-left" style="font-size: var(--text-xs); font-weight: 600; color: var(--color-text-faint); text-transform: uppercase; letter-spacing: 0.04em;">Note</th>
                                            <th class="money px-3 py-2 text-right" style="font-size: var(--text-xs); font-weight: 600; color: var(--color-text-faint); text-transform: uppercase; letter-spacing: 0.04em; width: 7rem;">Settled EUR</th>
                                            <th class="px-3 py-2 text-right" style="font-size: var(--text-xs); font-weight: 600; color: var(--color-text-faint); text-transform: uppercase; letter-spacing: 0.04em; width: 5rem;">Original</th>
                                            <th class="px-3 py-2 text-center" style="font-size: var(--text-xs); font-weight: 600; color: var(--color-text-faint); text-transform: uppercase; letter-spacing: 0.04em; width: 3rem;">Year</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($rows as $row)
                                            @php
                                                $bookedAt  = isset($row['bookedAt']) ? \Carbon\CarbonImmutable::parse($row['bookedAt']) : null;
                                                $hasOverride = isset($row['taxYearOverride']) && $row['taxYearOverride'] !== null;
                                                $settledMinor = is_int($row['settledAmountMinor']) ? $row['settledAmountMinor'] : 0;
                                                $origMinor    = is_int($row['amountMinor']) ? $row['amountMinor'] : 0;
                                                $origCurrency = is_string($row['currency']) ? $row['currency'] : BaseCurrency::value();
                                                $showOrig     = $origCurrency !== BaseCurrency::value();
                                            @endphp
                                            <tr
                                                class="triage-row border-b"
                                                style="border-color: var(--color-border);"
                                            >
                                                {{-- Date --}}
                                                <td class="date whitespace-nowrap px-3 py-2" style="color: var(--color-text-muted);">
                                                    {{ $bookedAt ? $bookedAt->format('d M Y') : '—' }}
                                                </td>
                                                {{-- Account --}}
                                                <td class="px-3 py-2 text-sm" style="color: var(--color-text-muted); max-width: 6rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                                    {{ $row['accountName'] ?? '—' }}
                                                </td>
                                                {{-- Counterparty --}}
                                                <td class="px-3 py-2" style="color: var(--color-text);">
                                                    @if (! empty($row['counterpartyName']))
                                                        {{ $row['counterpartyName'] }}
                                                    @else
                                                        <span style="color: var(--color-text-faint);">—</span>
                                                    @endif
                                                </td>
                                                {{-- Note --}}
                                                <td class="px-3 py-2" style="color: var(--color-text-muted); font-style: italic; max-width: 12rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                                    @if (! empty($row['note']))
                                                        {{ $row['note'] }}
                                                    @else
                                                        <span style="color: var(--color-text-faint); font-style: normal;">—</span>
                                                    @endif
                                                </td>
                                                {{-- Settled EUR --}}
                                                <td class="money px-3 py-2 text-right" style="color: var(--color-text);">
                                                    {{ $fmtEur(abs($settledMinor)) }}
                                                </td>
                                                {{-- Original (if non-EUR) --}}
                                                <td class="px-3 py-2 text-right" style="font-size: var(--text-xs); color: var(--color-text-faint);">
                                                    @if ($showOrig)
                                                        {{ $origCurrency }} {{ number_format(abs($origMinor) / Money::MINOR_UNITS_PER_MAJOR, 2) }}
                                                    @else
                                                        —
                                                    @endif
                                                </td>
                                                {{-- Year / override chip --}}
                                                <td class="px-3 py-2 text-center">
                                                    @if ($hasOverride)
                                                        <span class="tax-badge--amber" aria-label="Tax year overridden to {{ $row['taxYearOverride'] }}">
                                                            → {{ $row['taxYearOverride'] }}
                                                        </span>
                                                    @else
                                                        <span style="color: var(--color-text-faint); font-size: var(--text-xs);">—</span>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>

                                {{-- Phone: card-per-row (Section 17 — D-21 card-per-row pattern) --}}
                                <ul class="divide-y sm:hidden" style="border-color: var(--color-border);">
                                    @foreach ($rows as $row)
                                        @php
                                            $bookedAt  = isset($row['bookedAt']) ? \Carbon\CarbonImmutable::parse($row['bookedAt']) : null;
                                            $hasOverride = isset($row['taxYearOverride']) && $row['taxYearOverride'] !== null;
                                            $settledMinor = is_int($row['settledAmountMinor']) ? $row['settledAmountMinor'] : 0;
                                        @endphp
                                        <li class="px-4 py-3">
                                            <div class="flex items-start justify-between gap-2">
                                                <div class="min-w-0 flex-1">
                                                    <p style="font-size: var(--text-base); color: var(--color-text); font-weight: 500;">
                                                        {{ $row['counterpartyName'] ?? '—' }}
                                                    </p>
                                                    <p style="font-size: var(--text-xs); color: var(--color-text-muted); margin-top: 2px;">
                                                        {{ $bookedAt ? $bookedAt->format('d M Y') : '—' }}
                                                        @if (! empty($row['accountName']))
                                                            · {{ $row['accountName'] }}
                                                        @endif
                                                    </p>
                                                    @if (! empty($row['note']))
                                                        <p style="font-size: var(--text-base); color: var(--color-text-muted); font-style: italic; margin-top: 2px;">{{ $row['note'] }}</p>
                                                    @endif
                                                </div>
                                                <div class="flex flex-col items-end gap-1">
                                                    <span class="kpi-number" style="font-size: var(--text-base); font-weight: 600; color: var(--color-text);">
                                                        {{ $fmtEur(abs($settledMinor)) }}
                                                    </span>
                                                    @if ($hasOverride)
                                                        <span class="tax-badge--amber">→ {{ $row['taxYearOverride'] }}</span>
                                                    @endif
                                                </div>
                                            </div>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        </details>
                    @endforeach
                </div>

            @endif {{-- itemCount === 0 --}}
        @endif {{-- $data !== null --}}

    </div>
</div>
